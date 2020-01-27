<?php
/*
 * Copyright (c) Arnaud Ligny <arnaud@ligny.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Step;

use MatthiasMullie\Minify;

/**
 * JS files Optimization.
 */
class OptimizeJs extends AbstractStepOptimize
{
    public function setProcessor()
    {
        $this->type = 'js';
    }

    public function processFile(\Symfony\Component\Finder\SplFileInfo $file)
    {
        $minifier = new Minify\JS($file->getPathname());
        $minified = $minifier->minify();
        \Cecil\Util::getFS()->dumpFile($file->getPathname(), $minified);
    }
}
