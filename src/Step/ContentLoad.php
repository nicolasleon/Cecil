<?php
/**
 * This file is part of the Cecil/Cecil package.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Step;

use Cecil\Exception\Exception;
use Symfony\Component\Finder\Finder;

/**
 * Locates content.
 */
class ContentLoad extends AbstractStep
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'Loading content';
    }

    /**
     * {@inheritdoc}
     */
    public function init($options)
    {
        /** @var \Cecil\Builder $builder */
        if (is_dir($this->builder->getConfig()->getContentPath())) {
            $this->canProcess = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function process()
    {
        $content = Finder::create()
            ->files()
            ->in($this->builder->getConfig()->getContentPath())
            ->name('/\.('.implode('|', (array) $this->builder->getConfig()->get('content.ext')).')$/')
            ->sortByName(true);
        if (!$content instanceof Finder) {
            throw new Exception(sprintf('%s result must be an instance of Finder.', __CLASS__));
        }
        $this->builder->setContent($content);

        $count = $content->count();
        if ($count === 0) {
            $this->builder->getLogger()->info('Nothing to load');

            return 0;
        }
        $this->builder->getLogger()->info('Files loaded', ['progress' => [$count, $count]]);
    }
}
