<?php
/**
 * This file is part of the Cecil/Cecil package.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Converter;

use Cecil\Builder;
use Cecil\Exception\Exception;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Converter.
 */
class Converter implements ConverterInterface
{
    /**
     * {@inheritdoc}
     */
    public static function convertFrontmatter(string $string, string $type = 'yaml'): array
    {
        switch ($type) {
            case 'ini':
                $result = parse_ini_string($string);
                if (!$result) {
                    throw new Exception('Can\'t parse INI front matter');
                }

                return $result;
            case 'yaml':
            default:
                try {
                    $result = Yaml::parse((string) $string);
                    if (!is_array($result)) {
                        throw new Exception('Parse result of YAML front matter is not an array');
                    }

                    return $result;
                } catch (ParseException $e) {
                    throw new Exception($e->getMessage());
                }
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function convertBody(string $string, Builder $builder = null): string
    {
        $parsedown = new Parsedown($builder);

        return $parsedown->text($string);
    }
}
