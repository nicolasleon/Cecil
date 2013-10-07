#!/usr/bin/env php
<?php
/**
 * PHPoole is a light and easy static website generator written in PHP.
 * @see http://narno.org/PHPoole/
 *
 * @author Arnaud Ligny <arnaud@ligny.org>
 * @license The MIT License (MIT)
 *
 * Copyright (c) 2013 Arnaud Ligny
 */

//error_reporting(0);

use Zend\Console\Console;
use Zend\Console\ColorInterface as Color;
use Zend\Console\Getopt;
use Zend\Console\Exception\RuntimeException as ConsoleException;
use Zend\EventManager\EventManager;
use Michelf\MarkdownExtra;

// Composer autoloading
if (file_exists('vendor/autoload.php')) {
    $loader = include 'vendor/autoload.php';
}
else {
    echo 'Run the following commands:' . PHP_EOL;
    if (!file_exists('composer.json')) {
        echo 'curl https://raw.github.com/Narno/PHPoole/master/composer.json > composer.json' . PHP_EOL;
    }
    if (!file_exists('composer.phar')) {
        echo 'curl -s http://getcomposer.org/installer | php' . PHP_EOL;
    }  
    echo 'php composer.phar install' . PHP_EOL;
    exit(2);
}

try {
    $console = Console::getInstance();
    $phpooleConsole = new PHPooleConsole($console);
} catch (ConsoleException $e) {
    // Could not get console adapter - most likely we are not running inside a console window.
}

define('DS', DIRECTORY_SEPARATOR);
define('PHPOOLE_DIRNAME', '_phpoole');
$websitePath = getcwd();

// Defines rules
$rules = array(
    'help|h'     => 'Get PHPoole usage message',
    'init|i-s'   => 'Build a new PHPoole website (<force>)',
    'generate|g' => 'Generate static files',
    'serve|s'    => 'Start built-in web server',
    'deploy|d'   => 'Deploy static files',
    'list|l=s'   => 'Lists <pages> or <posts>',
);

// Get and parse console options
try {
    $opts = new Getopt($rules);
    $opts->parse();
} catch (ConsoleException $e) {
    echo $e->getUsageMessage();
    exit(2);
}

// help option
if ($opts->getOption('help') || count($opts->getOptions()) == 0) {
    echo $opts->getUsageMessage();
    exit(0);
}

// Get provided directory if exist
$remainingArgs = $opts->getRemainingArgs();
if (isset($remainingArgs[0])) {
    if (!is_dir($remainingArgs[0])) {
        $phpooleConsole->wlError('Invalid directory provided');
        exit(2);
    }
    $websitePath = str_replace(DS, '/', realpath($remainingArgs[0]));
}

// Instanciate PHPoole API
try {
    $phpoole = new PHPoole($websitePath);
}
catch (Exception $e) {
    $phpooleConsole->wlError($e->getMessage());
    exit(2);
}

// init option
if ($opts->getOption('init')) {
    $force = false;
    $phpooleConsole->wlInfo('Initializing new website');
    if ((string)$opts->init == 'force') {
        $force = true;
    }
    try {
        $messages = $phpoole->init($force);
        foreach ($messages as $message) {
            $phpooleConsole->wlDone($message);
        }
    }  
    catch (Exception $e) {
        $phpooleConsole->wlError($e->getMessage());
    }
}

// generate option
if ($opts->getOption('generate')) {
    $generateConfig = array();
    $phpooleConsole->wlInfo('Generate website');
    if (isset($opts->serve)) {
        $generateConfig['site']['base_url'] = 'http://localhost:8000';
        $phpooleConsole->wlInfo('Youd should re-generate before deploy');
    }
    try {
        $messages = $phpoole->generate($generateConfig);
        foreach ($messages as $message) {
            $phpooleConsole->wlDone($message);
        }
    }  
    catch (Exception $e) {
        $phpooleConsole->wlError($e->getMessage());
    }
}

// serve option
if ($opts->getOption('serve')) {
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        $phpooleConsole->wlError('PHP 5.4+ required to run built-in server (your version: ' . PHP_VERSION . ')');
        exit(2);
    }
    if (!is_file(sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME))) {
        $phpooleConsole->wlError('Router not found');
        exit(2);
    }
    $phpooleConsole->wlInfo(sprintf("Start server http://%s:%d", 'localhost', '8000'));
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = sprintf(
            //'START /B php -S %s:%d -t %s %s > nul',
            'START php -S %s:%d -t %s %s > nul',
            'localhost',
            '8000',
            $websitePath,
            sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME)
        );
    }
    else {
        echo 'Ctrl-C to stop it.' . PHP_EOL;
        $command = sprintf(
            //'php -S %s:%d -t %s %s >/dev/null 2>&1 & echo $!',
            'php -S %s:%d -t %s %s >/dev/null',
            'localhost',
            '8000',
            $websitePath,
            sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME)
        );
    }
    exec($command);
}

// deploy option
if ($opts->getOption('deploy')) {
    $phpooleConsole->wlInfo('Deploy website on GitHub');
    try {
        $config = $phpoole->getConfig();
        if (!isset($config['deploy']['repository']) && !isset($config['deploy']['branch'])) {
            throw new Exception('Cannot found the repository name in the config file');
        }
        else {
            $repoUrl = $config['deploy']['repository'];
            $repoBranch = $config['deploy']['branch'];
        }
        $deployDir = $phpoole->getWebsitePath() . '/../.' . basename($phpoole->getWebsitePath());
        if (is_dir($deployDir)) {
            //echo 'Deploying files to GitHub...' . PHP_EOL;
            $deployIterator = new FilesystemIterator($deployDir);
            foreach ($deployIterator as $deployFile) {
                if ($deployFile->isFile()) {
                    @unlink($deployFile->getPathname());
                }
                if ($deployFile->isDir() && $deployFile->getFilename() != '.git') {
                    RecursiveRmDir($deployFile->getPathname());
                }
            }
            RecursiveCopy($phpoole->getWebsitePath(), $deployDir);
            $updateRepoCmd = array(
                'add -A',
                'commit -m "Update ' . $repoBranch . ' via PHPoole"',
                'push github ' . $repoBranch . ' --force'
            );
            runGitCmd($deployDir, $updateRepoCmd);
        }
        else {
            //echo 'Setting up GitHub deployment...' . PHP_EOL;
            @mkdir($deployDir);
            RecursiveCopy($phpoole->getWebsitePath(), $deployDir);
            $initRepoCmd = array(
                'init',
                'add -A',
                'commit -m "Create ' . $repoBranch . ' via PHPoole"',
                'branch -M ' . $repoBranch . '',
                'remote add github ' . $repoUrl,
                'push github ' . $repoBranch . ' --force'
            );
            runGitCmd($deployDir, $initRepoCmd);
        }
    }  
    catch (Exception $e) {
        $phpooleConsole->wlError($e->getMessage());
    }
}

// list option
if ($opts->getOption('list')) {
    if (isset($opts->list) && $opts->list == 'pages') {
        try {
            $phpooleConsole->wlInfo('List pages');
            $pages = $phpoole->getPages();
            foreach($pages as $page => $pagePath) {
                printf("- %s\n", $pagePath);
            }
        }
        catch (Exception $e) {
            $phpooleConsole->wlError($e->getMessage());
        }
    }
    else if (isset($opts->list) && $opts->list == 'posts') {
        $phpooleConsole->wlInfo('List posts');
        // @todo todo! :-)
    }
    else {
        echo $opts->getUsageMessage();
        exit(2);
    }
}

/**
 * PHPoole API
 */
class PHPoole
{
    const PHPOOLE_DIRNAME = '_phpoole';
    const CONFIG_FILENAME = 'config.ini';
    const LAYOUTS_DIRNAME = 'layouts';
    const ASSETS_DIRNAME  = 'assets';
    const CONTENT_DIRNAME = 'content';
    const CONTENT_PAGES_DIRNAME = 'pages';
    const CONTENT_POSTS_DIRNAME = 'posts';
    const PLUGINS_DIRNAME  = 'plugins';

    protected $websitePath;
    protected $websiteFileInfo;
    protected $events;

    public function __construct($websitePath)
    {
        $splFileInfo = new SplFileInfo($websitePath);
        if (!$splFileInfo->isDir()) {
            throw new Exception('Invalid directory provided');
        }
        else {
            $this->websiteFileInfo = $splFileInfo;
            $this->websitePath = $splFileInfo->getRealPath();
        }
        // Load plugins
        $this->events = new EventManager();
        $this->loadPlugins();
    }

    public function getWebsiteFileInfo()
    {
        return $this->websiteFileInfo;
    }

    public function getWebsitePath()
    {
        return $this->websitePath;
    }

    public function init($force=false)
    {
        $results = $this->events->trigger(__FUNCTION__ . '.pre', $this, compact('force'));
        if ($results->last()) {
            extract($results->last());
        }
        if (file_exists($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONFIG_FILENAME)) {
            if ($force === true) {
                RecursiveRmdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME);
            }
            else {
                throw new Exception('The website is already initialized');
            }
        }
        if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME)) {
            throw new Exception('Cannot create root PHPoole directory');
        }
        $messages = array(
            self::PHPOOLE_DIRNAME . ' directory created',
            $this->createConfigFile(),
            $this->createLayoutsDir(),
            $this->createLayoutDefaultFile(),
            $this->createAssetsDir(),
            $this->createAssetDefaultFiles(),
            $this->createContentDir(),
            $this->createContentDefaultFile(),
            $this->createRouterFile(),
        );
        $results = $this->events->trigger(__FUNCTION__ . '.post', $this, compact('force', 'messages'));
        if ($results->last()) {
            extract($results->last());
        }
        return $messages;
    }

    private function createConfigFile()
    {
        $content = <<<'EOT'
[site]
name        = "PHPoole"
baseline    = "Light and easy static website generator!"
description = "PHPoole is a light and easy static website / blog generator written in PHP. It parses your content written with Markdown, merge it with layouts and generates static HTML files."
base_url    = "http://localhost:8000"
language    = "en"
[author]
name  = "Arnaud Ligny"
email = "arnaud+phpoole@ligny.org"
home  = "http://narno.org"
[deploy]
repository = "https://github.com/Narno/PHPoole.git"
branch     = "gh-pages"
EOT;

        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONFIG_FILENAME, $content)) {
            throw new Exception('Cannot create the config file');
        }
        return 'Config file created';
    }

    private function createLayoutsDir()
    {
        if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::LAYOUTS_DIRNAME)) {
            throw new Exception('Cannot create the layouts directory');
        }
        return 'Layouts directory created';
    }

    private function createLayoutDefaultFile()
    {
        $content = <<<'EOT'
<!DOCTYPE html>
<!--[if IE 8]><html class="no-js lt-ie9" lang="{{ site.language }}"><![endif]-->
<!--[if gt IE 8]><!--><html class="no-js" lang="{{ site.language }}"><!--<![endif]-->
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <meta name="description" content="{{ site.description }}">
  <title>{{ site.name}} - {{ title }}</title>
  <style type="text/css">
    body { font: bold 24px Helvetica, Arial; padding: 15px 20px; color: #ddd; background: #333;}
    a:link {text-decoration: none; color: #fff;}
    a:visited {text-decoration: none; color: #fff;}
    a:active {text-decoration: none; color: #fff;}
    a:hover {text-decoration: underline; color: #fff;}
  </style>
</head>
<body>
  <a href="{{ site.base_url}}"><strong>{{ site.name }}</strong></a><br />
  <em>{{ site.baseline }}</em>
  <hr />
  <p>{{ content }}</p>
  <hr />
  <p>Powered by <a href="http://narno.org/PHPoole">PHPoole</a>, coded by <a href="{{ author.home }}">{{ author.name }}</a></p>
</body>
</html>
EOT;
        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::LAYOUTS_DIRNAME . '/default.html', $content)) {
            throw new Exception('Cannot create the default layout file');
        }
        return 'Default layout file created';
    }

    private function createAssetsDir()
    {
        $subDirList = array(
            self::ASSETS_DIRNAME,
            self::ASSETS_DIRNAME . '/css',
            self::ASSETS_DIRNAME . '/img',
            self::ASSETS_DIRNAME . '/js',
        );
        foreach ($subDirList as $subDir) {
            if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . $subDir)) {
                throw new Exception('Cannot create the assets directory');
            }
        }
        return 'Assets directory created';
    }

    private function createAssetDefaultFiles()
    {
        return 'Default assets files not needed';
    }

    private function createContentDir()
    {
        $subDirList = array(
            self::CONTENT_DIRNAME,
            self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME,
            self::CONTENT_DIRNAME . '/' . self::CONTENT_POSTS_DIRNAME,
        );
        foreach ($subDirList as $subDir) {
            if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . $subDir)) {
                throw new Exception('Cannot create the content directory');
            }
        }
        return 'Content directory created';
    }

    private function createContentDefaultFile()
    {
        $content = <<<'EOT'
<!--
title = Home
layout = default
menu = nav
-->
PHPoole is a light and easy static website / blog generator written in PHP.
It parses your content written with Markdown, merge it with layouts and generates static HTML files.

PHPoole = [PHP](http://www.php.net) + [Poole](http://en.wikipedia.org/wiki/Strange_Case_of_Dr_Jekyll_and_Mr_Hyde#Mr._Poole)

Go to the [dedicated website](http://narno.org/PHPoole) for more details.
EOT;
        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME . '/index.md', $content)) {
            throw new Exception('Cannot create the default content file');
        }
        return 'Default content file created';
    }

    private function createRouterFile()
    {
        $content = <<<'EOT'
<?php
date_default_timezone_set("UTC");
define("DIRECTORY_INDEX", "index.html");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$ext = pathinfo($path, PATHINFO_EXTENSION);
if (empty($ext)) {
    $path = rtrim($path, "/") . "/" . DIRECTORY_INDEX;
}
if (file_exists($_SERVER["DOCUMENT_ROOT"] . $path)) {
    return false;
}
http_response_code(404);
echo "404, page not found";
EOT;
        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/router.php', $content)) {
            throw new Exception('Cannot create the router file');
        }
        return 'Router file created';
    }

    private function createReadmeFile()
    {
        $content = <<<'EOT'
Powered by [PHPoole](http://narno.org/PHPoole/).
EOT;
        
        if (is_file($this->getWebsitePath() . '/README.md')) {
            if (!@unlink($this->getWebsitePath() . '/README.md')) {
                throw new Exception('Cannot create the README file');
            }
        }
        if (!@file_put_contents($this->getWebsitePath() . '/README.md', $content)) {
            throw new Exception('Cannot create the README file');
        }
        return 'README file created';
    }

    public function getConfig()
    {
        $configFilePath = $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONFIG_FILENAME;
        if (!file_exists($configFilePath)) {
            throw new Exception('Cannot get config file');
        }
        return parse_ini_file($configFilePath, true);
    }

    public function parseContent($content, $filename, $config)
    {
        $config = $this->getConfig();
        $parser = new MarkdownExtra;
        $parser->code_attr_on_pre = true;
        $parser->predef_urls = array('base_url' => $config['site']['base_url']);
        preg_match('/^<!--(.+)-->(.+)/s', $content, $matches);
        if (!$matches) {
            //throw new Exception(sprintf("Could not parse front matter in %s\n", $filename));
            return array('content' => $contentHtml = $parser->transform($content));
        }
        list($matchesAll, $rawInfo, $rawContent) = $matches;
        $info = parse_ini_string($rawInfo);
        if (isset($info['source']) /* && is valid URL to md file */) {
            if (false === ($rawContent = @file_get_contents($info['source'], false))) {
                throw new Exception(sprintf("Cannot get content from %s\n", $filename));
            }
        }
        $contentHtml = $parser->transform($rawContent);
        return array_merge(
            $info,
            array('content' => $contentHtml)
        );
    }

    public function generate($configToMerge=array())
    {
        $pages = array();
        $menu['nav'] = array();
        $config = $this->getConfig();
        if (!empty($configToMerge)) {
            $config = array_replace_recursive($config, $configToMerge);
        }
        $twigLoader = new Twig_Loader_Filesystem($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::LAYOUTS_DIRNAME);
        $twig = new Twig_Environment($twigLoader, array(
            'autoescape' => false,
            'debug'      => true
        ));
        $twig->addExtension(new Twig_Extension_Debug());
        $pagesPath = $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME;
        $markdownIterator = new FileFilterIterator($pagesPath, 'md');
        foreach ($markdownIterator as $filePage) {
            if (false === ($content = @file_get_contents($filePage->getPathname()))) {
                throw new Exception(sprintf('Cannot get content of %s/%s', $markdownIterator->getSubPath(), $filePage->getBasename()));
            }
            $page = $this->parseContent($content, $filePage->getFilename(), $config);
            $pageIndex = ($markdownIterator->getSubPath() ? $markdownIterator->getSubPath() : 'home');
            $pages[$pageIndex]['layout'] = (
                isset($page['layout'])
                    && is_file($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/layouts' . '/' . $page['layout'] . '.html')
                ? $page['layout'] . '.html'
                : 'default.html'
            );
            $pages[$pageIndex]['title'] = (
                isset($page['title'])
                    && !empty($page['title'])
                ? $page['title']
                : ucfirst($filePage->getBasename('.md'))
            );
            $pages[$pageIndex]['path'] = $markdownIterator->getSubPath();
            $pages[$pageIndex]['content'] = $page['content'];
            $pages[$pageIndex]['basename'] = $filePage->getBasename('.md') . '.html';
            if (isset($page['menu'])) {
                $menu[$page['menu']][] = (
                    !empty($page['menu'])
                    ? array(
                        'title' => $page['title'],
                        'path'  => $markdownIterator->getSubPath()
                    )
                    : ''
                );
            }
        }
        foreach ($pages as $key => $page) {
            $rendered = $twig->render($page['layout'], array(
                'site'    => $config['site'],
                'author'  => $config['author'],
                'source'  => $config['deploy'],
                'title'   => $page['title'],
                'path'    => $page['path'],
                'content' => $page['content'],
                'nav'     => $menu['nav'],
            ));
            if (!is_dir($this->getWebsitePath() . '/' . $page['path'])) {
                if (!@mkdir($this->getWebsitePath() . '/' . $page['path'], 0777, true)) {
                    throw new Exception(sprintf('Cannot create %s', $this->getWebsitePath() . '/' . $page['path']));
                }
            }
            if (is_file($this->getWebsitePath() . '/' . ($page['path'] != '' ? $page['path'] . '/' : '') . $page['basename'])) {
                if (!@unlink($this->getWebsitePath() . '/' . ($page['path'] != '' ? $page['path'] . '/' : '') . $page['basename'])) {
                    throw new Exception(sprintf('Cannot delete %s%s', ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']));
                }
                $messages[] = 'Delete ' . ($page['path'] != '' ? $page['path'] . '/' : '') . $page['basename'];
            }
            if (!@file_put_contents(sprintf('%s%s', $this->getWebsitePath() . '/' . ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']), $rendered)) {
                throw new Exception(sprintf('Cannot write %s%s', ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']));
            }
            $messages[] = sprintf("Write %s%s", ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']);
            
        }
        if (is_dir($this->getWebsitePath() . '/' . self::LAYOUTS_DIRNAME)) {
            RecursiveRmdir($this->getWebsitePath() . '/' . self::LAYOUTS_DIRNAME);
        }
        RecursiveCopy($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::ASSETS_DIRNAME, $this->getWebsitePath() . '/' . self::ASSETS_DIRNAME);
        $messages[] = 'Copy assets directory (and sub)';
        $messages[] = $this->createReadmeFile();
        return $messages;
    }

    public function getPages()
    {
        $pages = array();
        $pagesPath = $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME;
        if (!is_dir($pagesPath)) {
            throw new Exception('Invalid content/pages directory');
        }
        $pagesIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pagesPath),
            RecursiveIteratorIterator::CHILD_FIRST
        );    
        foreach($pagesIterator as $page) {
            if ($page->isFile()) {
                $pages = array($page->getFilename() => ($pagesIterator->getSubPath() != '' ? $pagesIterator->getSubPath() . '/' : '') . $page->getFilename());
            }
        }
        return $pages;
    }

    private function loadPlugins()
    {
        $pluginsDir = __DIR__ . '/' . self::PLUGINS_DIRNAME;
        if (is_dir($pluginsDir)) {
            $pluginsIterator = new FilesystemIterator($pluginsDir);
            foreach ($pluginsIterator as $plugin) {
                if ($plugin->isDir()) {
                    include_once("$plugin/Plugin.php");
                    $pluginName = $plugin->getBasename();
                    if (class_exists($pluginName)) {
                        $pluginclass = new $pluginName($this->events);
                        if (method_exists($pluginclass, 'preInit')) {
                            $this->events->attach('init.pre', array($pluginclass, 'preInit'));
                        }
                        if (method_exists($pluginclass, 'postInit')) {
                            $this->events->attach('init.post', array($pluginclass, 'postInit'));
                        }
                    }
                }
            }
        }
    }
}

/**
 * PHPoole console helper
 */
class PHPooleConsole
{
    protected $console;

    public function __construct($console)
    {
        if (!($console instanceof Zend\Console\Adapter\AdapterInterface)) {
            throw new Exception("Error");
        }
        $this->console = $console;
    }

    public function wlInfo($text)
    {
        echo '[' , $this->console->write('INFO', Color::YELLOW) , ']' . "\t";
        $this->console->writeLine($text);
    }
    public function wlDone($text)
    {
        echo '[' , $this->console->write('DONE', Color::GREEN) , ']' . "\t";
        $this->console->writeLine($text);
    }
    public function wlError($text)
    {
        echo '[' , $this->console->write('ERROR', Color::RED) , ']' . "\t";
        $this->console->writeLine($text);
    }
}

/**
 * PHPoole plugin abstract
 */
abstract class PHPoole_Plugin
{
    const DEBUG = false;

    public function __call($name, $args)
    {
        if (self::DEBUG) {
            printf("[EVENT] %s is not implemented in %s plugin\n", $name, get_class($this));
        }
    }

    public function trace($enabled=self::DEBUG, $event, $params, $state='')
    {
        if ($enabled === true) {
            printf(
                '[EVENT] %s/%s %s %s' . "\n",
                get_class($this),
                $event,
                $state,
                json_encode($params)
            );
        }
    }
}

/**
 * Utils
 */

/**
 * Recursively remove a directory
 *
 * @param string $dirname
 * @param boolean $followSymlinks
 * @return boolean
 */
function RecursiveRmdir($dirname, $followSymlinks=false) {
    if (is_dir($dirname) && !is_link($dirname)) {
        if (!is_writable($dirname)) {
            throw new Exception(sprintf('%s is not writable!', $dirname));
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        while ($iterator->valid()) {
            if (!$iterator->isDot()) {
                if (!$iterator->isWritable()) {
                    throw new Exception(sprintf(
                        '%s is not writable!',
                        $iterator->getPathName()
                    ));
                }
                if ($iterator->isLink() && $followLinks === false) {
                    $iterator->next();
                }
                if ($iterator->isFile()) {
                    @unlink($iterator->getPathName());
                }
                elseif ($iterator->isDir()) {
                    @rmdir($iterator->getPathName());
                }
            }
            $iterator->next();
        }
        unset($iterator);
 
        return @rmdir($dirname);
    }
    else {
        throw new Exception(sprintf('%s does not exist!', $dirname));
    }
}

/**
 * Copy a dir, and all its content from source to dest
 */
function RecursiveCopy($source, $dest) {
    if (!is_dir($dest)) {
        @mkdir($dest);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @mkdir($dest . DS . $iterator->getSubPathName());
        }
        else {
            @copy($item, $dest . DS . $iterator->getSubPathName());
        }
    }
}

/**
 * File filter/iterator
 */
class FileFilterIterator extends FilterIterator
{
    public function __construct($dirOrIterator = '.', $extension='')
    {
        if (is_string($dirOrIterator)) {
            if (!is_dir($dirOrIterator)) {
                throw new InvalidArgumentException('Expected a valid directory name');
            }
            $dirOrIterator = new RecursiveDirectoryIterator($dirOrIterator, RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        }
        elseif (!$dirOrIterator instanceof DirectoryIterator) {
            throw new InvalidArgumentException('Expected a DirectoryIterator');
        }
        if ($dirOrIterator instanceof RecursiveIterator) {
            $dirOrIterator = new RecursiveIteratorIterator($dirOrIterator);
        }
        parent::__construct($dirOrIterator);
    }

    public function accept()
    {
        $file = $this->getInnerIterator()->current();
        if (!$file instanceof SplFileInfo) {
            return false;
        }
        if (!$file->isFile()) {
            return false;
        }
        if (isset($extension) && is_string($extension)) {
            if ($file->getExtension() == $extension) {
                return true;
            }
        }
        else {
            return true;
        }
    }
}

/**
 * Execute git commands
 * 
 * @param string working directory
 * @param array git commands
 * @return void
 */
function runGitCmd($wd, $commands)
{
    $cwd = getcwd();
    chdir($wd);
    exec('git config core.autocrlf false');
    foreach ($commands as $cmd) {
        //printf("> git %s\n", $cmd);
        exec(sprintf('git %s', $cmd));
    }
    chdir($cwd);
}