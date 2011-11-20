<?php
/**
 * CSSTidy - CSS Parser and Optimiser
 *
 * CSS Optimising Class
 * This class optimises CSS data generated by csstidy.
 *
 * Copyright 2005, 2006, 2007 Florian Schmitz
 *
 * This file is part of CSSTidy.
 *
 *   CSSTidy is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Lesser General Public License as published by
 *   the Free Software Foundation; either version 2.1 of the License, or
 *   (at your option) any later version.
 *
 *   CSSTidy is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Lesser General Public License for more details.
 * 
 *   You should have received a copy of the GNU Lesser General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @license http://opensource.org/licenses/lgpl-license.php GNU Lesser General Public License
 * @package CSSTidy
 * @author Florian Schmitz (floele at gmail dot com) 2005-2007
 * @author Brett Zamir (brettz9 at yahoo dot com) 2007
 * @author Nikolay Matsievsky (speed at webo dot name) 2009-2010
 * @author Jakub Onderka (acci at acci dot cz) 2011
 */
namespace CSSTidy;

require_once __DIR__ . '/optimise/Color.php';
require_once __DIR__ . '/optimise/Number.php';

/**
 * CSS Optimising Class
 *
 * This class optimises CSS data generated by csstidy.
 *
 * @package csstidy
 * @author Florian Schmitz (floele at gmail dot com) 2005-2006
 * @version 1.0
 */
class Optimise
{
    /** @var \CSSTidy\Logger */
    protected $logger;

    /** @var \CSSTidy\Configuration */
    protected $configuration;

    /** @var \CSSTidy\Optimise\Color */
    protected $optimiseColor;

    /** @var \CSSTidy\Optimise\Number */
    protected $optimiseNumber;

    /**
     * A list of all shorthand properties that are devided into four properties and/or have four subvalues
     *
     * @todo Are there new ones in CSS3?
     * @see dissolveFourValueShorthands()
     * @see mergeFourValueShorthands()
     * @var array
     */
    public static $shorthands = array(
        'border-color' => array('border-top-color','border-right-color','border-bottom-color','border-left-color'),
        'border-style' => array('border-top-style','border-right-style','border-bottom-style','border-left-style'),
        'border-width' => array('border-top-width','border-right-width','border-bottom-width','border-left-width'),
        'margin' => array('margin-top','margin-right','margin-bottom','margin-left'),
        'padding' => array('padding-top','padding-right','padding-bottom','padding-left'),
        'border-radius' => array('border-radius-top-left', 'border-radius-top-right', 'border-radius-bottom-right', 'border-radius-bottom-left')
    );

    public static $twoValuesShorthand = array(
        'overflow' => array('overflow-x', 'overflow-y'),
        'pause' => array('pause-before', 'pause-after'),
        'rest' => array('rest-before', 'rest-after'),
        'cue' => array('cue-before', 'cue-after'),
    );

    /**
     * Default values for the background properties
     *
     * @todo Possibly property names will change during CSS3 development
     * @see dissolveShortBackground()
     * @see merge_bg()
     * @var array
     */
    public static $backgroundPropDefault = array(
        'background-image' => 'none',
        'background-size' => 'auto',
        'background-repeat' => 'repeat',
        'background-position' => '0 0',
        'background-attachment' => 'scroll',
        'background-clip' => 'border',
        'background-origin' => 'padding',
        'background-color' => 'transparent'
    );

    /**
     * Default values for the font properties
     *
     * @see mergeFonts()
     * @var array
     */
    public static $fontPropDefault = array(
        'font-style' => 'normal',
        'font-variant' => 'normal',
        'font-weight' => 'normal',
        'font-size' => '',
        'line-height' => '',
        'font-family' => '',
    );

    public function __construct(
        Logger $logger,
        Configuration $configuration,
        \CSSTidy\Optimise\Color $optimiseColor,
        \CSSTidy\Optimise\Number $optimiseNumber
    ) {
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->optimiseColor = $optimiseColor;
        $this->optimiseNumber = $optimiseNumber;
    }

    /**
     * @param Parsed $parsed
     * @return mixed
     */
    public function postparse(Parsed $parsed)
    {
        if ($this->configuration->getPreserveCss()) {
            return;
        }

        if ($this->configuration->getOptimiseShorthands() > Configuration::NOTHING) {
            $this->processShorthandsForBlock($parsed);
        }
    }

    /**
     * @param Block $block
     */
    public function processShorthandsForBlock(Block $block)
    {
        $this->dissolveShorthands($block);

        $this->mergeFourValueShorthands($block);
        $this->mergeTwoValuesShorthand($block);

        if ($this->configuration->getOptimiseShorthands() >= Configuration::FONT) {
            $this->mergeFont($block);

            if ($this->configuration->getOptimiseShorthands() >= Configuration::BACKGROUND) {
                $this->mergeBackground($block);

                if (empty($block->properties)) {
                    unset($block);
                }
            }
        }

        if (isset($block) && $block instanceof AtBlock) {
            foreach ($block->properties as $value) {
                if ($value instanceof Block) {
                    $this->processShorthandsForBlock($value);
                }
            }
        }
    }

    /**
     * Optimises values
     * @param string $property
     * @param string $value
     * @return string
     */
    public function value($property, $value)
    {
        // optimise shorthand properties
        if (isset(self::$shorthands[$property])) {
            if ($property === 'border-radius') {
                $temp = $this->borderRadiusShorthand($value);
            } else {
                $temp = $this->compressShorthand($value); // FIXME - move
            }
            if ($temp != $value) {
                $this->logger->log("Optimised shorthand notation ($property): Changed '$value' to '$temp'", Logger::INFORMATION);
            }
            $value = $temp;
        }

        if ($property === 'background-image' && $this->configuration->getCompressColors()) {
            $value = $this->optimizeGradients($value);
        } else if ($this->removeVendorPrefix($property) === 'transform') {
            $value = $this->optimizeTransform($value);
        }

        // Remove whitespace at ! important
        $tmp = $this->compressImportant($value);
        if ($value != $tmp) {
            $value = $tmp;
            $this->logger->log('Optimised !important', Logger::INFORMATION);
        }

        return $value;
    }

    /**
     * Optimises a sub-value
     * @version 1.1
     * @param string $property
     * @param string $subValue
     * @return string
     */
    public function subValue($property, $subValue)
    {
        $important = '';
        if (CSSTidy::isImportant($subValue)) {
            $important = '!important';
            $subValue = CSSTidy::removeImportant($subValue, false);
        }

        // Compress font-weight
        if ($property === 'font-weight' && $this->configuration->getCompressFontWeight()) {
            static $optimizedFontWeight = array('bold' => 700, 'normal' => 400);
            if (isset($optimizedFontWeight[$subValue])) {
                $optimized = $optimizedFontWeight[$subValue];
                $this->logger->log("Optimised font-weight: Changed '$subValue' to '$optimized'", Logger::INFORMATION);
                $subValue = $optimized;
            }

            return $subValue . $important;
        }

        $subValue = $this->optimiseNumber->optimise($property, $subValue);

        if ($this->configuration->getCompressColors()) {
            $subValue = $this->optimiseColor->optimise($subValue);
        }

        $subValue = $this->optimizeCalc($subValue);

        return $subValue . $important;
    }

    /**
     * Removes unnecessary whitespace in ! important
     * @param string $string
     * @return string
     * @access public
     * @version 1.1
     */
    public function compressImportant($string)
    {
        if (CSSTidy::isImportant($string)) {
            return CSSTidy::removeImportant($string, false) . '!important';
        }

        return $string;
    }

    /**
     * @param Block $block
     */
    protected function dissolveShorthands(Block $block)
    {
        if (isset($block->properties['font']) && $this->configuration->getOptimiseShorthands() > Configuration::COMMON) {
            $value = $block->properties['font'];
            $block->properties['font'] = '';
            $block->mergeProperties($this->dissolveShortFont($value));
        }

        if (isset($block->properties['background']) && $this->configuration->getOptimiseShorthands() > Configuration::FONT) {
            $value = $block->properties['background'];
            $block->properties['background'] = '';
            $block->mergeProperties($this->dissolveShortBackground($value));
        }

        foreach (self::$shorthands as $shorthand => $foo) {
            if (isset($block->properties[$shorthand])) {
                $block->mergeProperties($this->dissolveFourValueShorthands($shorthand, $block->properties[$shorthand]));
                $block->properties[$shorthand] = '';
            }
        }
    }

    /**
     * Optimize border-radius property
     *
     * @param string $value
     * @return string
     */
    protected function borderRadiusShorthand($value)
    {
        $parts = explode('/', $value);

        if (empty($parts)) { // / delimiter in string not found
            return $value;
        }

        if (isset($parts[2])) {
            return $value; // border-radius value can contains only two parts
        }

        foreach ($parts as &$part) {
            $part = $this->compressShorthand(trim($part));
        }

        return implode('/', $parts);
    }

    /**
     * Compresses shorthand values. Example: margin:1px 1px 1px 1px -> margin:1px
     * @param string $value
     * @return string
     * @version 1.0
     */
    protected function compressShorthand($value)
    {
        $important = false;
        if (CSSTidy::isImportant($value)) {
            $value = CSSTidy::removeImportant($value, false);
            $important = true;
        }

        $values = $this->explodeWs(' ', $value);

        return $this->compressShorthandValues($values, $important);
    }

    /**
     * @param array $values
     * @param bool $isImportant
     * @return string
     */
    protected function compressShorthandValues(array $values, $isImportant)
    {
        $important = $isImportant ? '!important' : '';

        switch (count($values)) {
            case 4:
                if ($values[0] == $values[1] && $values[0] == $values[2] && $values[0] == $values[3]) {
                    return $values[0] . $important;
                } else if ($values[1] == $values[3] && $values[0] == $values[2]) {
                    return $values[0] . ' ' . $values[1] . $important;
                } else if ($values[1] == $values[3]) {
                    return $values[0] . ' ' . $values[1] . ' ' . $values[2] . $important;
                }
                break;

            case 3:
                if ($values[0] == $values[1] && $values[0] == $values[2]) {
                    return $values[0] . $important;
                } else if ($values[0] == $values[2]) {
                    return $values[0] . ' ' . $values[1] . $important;
                }
                break;

            case 2:
                if ($values[0] == $values[1]) {
                    return $values[0] . $important;
                }
                break;
        }

        return implode(' ', $values);
    }

    /**
     * Dissolves properties like padding:10px 10px 10px to padding-top:10px;padding-bottom:10px;...
     * @param string $property
     * @param string $value
     * @return array
     */
    protected function dissolveFourValueShorthands($property, $value)
    {
        $shorthands = self::$shorthands[$property];

        $important = '';
        if (CSSTidy::isImportant($value)) {
            $value = CSSTidy::removeImportant($value, false);
            $important = '!important';
        }

        $values = $this->explodeWs(' ', $value);

        $return = array();
        switch (count($values)) {
            case 4:
                for ($i = 0; $i < 4; $i++) {
                    $return[$shorthands[$i]] = $values[$i] . $important;
                }
                break;

            case 3:
                $return[$shorthands[0]] = $values[0] . $important;
                $return[$shorthands[1]] = $values[1] . $important;
                $return[$shorthands[3]] = $values[1] . $important;
                $return[$shorthands[2]] = $values[2] . $important;
                break;

            case 2:
                for ($i = 0; $i < 4; $i++) {
                    $return[$shorthands[$i]] = $values[$i % 2] . $important;
                }
                break;

            default:
                for ($i = 0; $i < 4; $i++) {
                    $return[$shorthands[$i]] = $values[0] . $important;
                }
                break;
        }

        return $return;
    }

    /**
     * Merges Shorthand properties again, the opposite of self::dissolveFourValueShorthands
     * @param Block $block
     */
    protected function mergeFourValueShorthands(Block $block)
    {
        foreach (self::$shorthands as $shorthand => $properties) {
            if (
                isset($block->properties[$properties[0]]) &&
                isset($block->properties[$properties[1]]) &&
                isset($block->properties[$properties[2]]) &&
                isset($block->properties[$properties[3]])
            ) {

                $important = false;
                $values = array();
                foreach ($properties as $property) {
                    $val = $block->properties[$property];
                    if (CSSTidy::isImportant($val)) {
                        $important = true;
                        $values[] = CSSTidy::removeImportant($val, false);
                    } else {
                        $values[] = $val;
                    }
                    unset($block->properties[$property]);
                }

                $block->properties[$shorthand] = $this->compressShorthandValues($values, $important);
            }
        }
    }

    /**
     * Merge two values shorthand
     * Shorthand for merging are defined in self::$twoValuesShorthand
     * Example: overflow-x and overflow-y are merged to overflow shorthand
     * @param Block $block
     * @see self::$twoValuesShorthand
     */
    protected function mergeTwoValuesShorthand(Block $block)
    {
        foreach (self::$twoValuesShorthand as $shorthandProperty => $properties) {
            if (
                isset($block->properties[$properties[0]]) &&
                isset($block->properties[$properties[1]])
            ) {
                $first = $block->properties[$properties[0]];
                $second = $block->properties[$properties[1]];

                if (CSSTidy::isImportant($first) !== CSSTidy::isImportant($second)) {
                    continue;
                }

                $important = CSSTidy::isImportant($first) ? '!important' : '';

                if ($important) {
                    $first = CSSTidy::removeImportant($first, false);
                    $second = CSSTidy::removeImportant($second, false);
                }

                if ($first == $second) {
                    $output = $first . $important;
                } else {
                    $output = "$first $second$important";
                }

                $block->properties[$shorthandProperty] = $output;
                unset($block->properties[$properties[0]], $block->properties[$properties[1]]);
            }
        }
    }


    /**
     * Dissolve background property
     * @param string $str_value
     * @return array
     * @todo full CSS 3 compliance
     */
    protected function dissolveShortBackground($str_value)
    {
        // don't try to explose background gradient !
        if (stripos($str_value, "gradient(") !== false) {
            return array('background' => $str_value);
        }
        
        static $repeat = array('repeat', 'repeat-x', 'repeat-y', 'no-repeat', 'space');
        static $attachment = array('scroll', 'fixed', 'local');
        static $clip = array('border', 'padding');
        static $origin = array('border', 'padding', 'content');
        static $pos = array('top', 'center', 'bottom', 'left', 'right');

        $return = array(
            'background-image' => null,
            'background-size' => null,
            'background-repeat' => null,
            'background-position' => null,
            'background-attachment' => null,
            'background-clip' => null,
            'background-origin' => null,
            'background-color' => null
        );

        $important = '';
        if (CSSTidy::isImportant($str_value)) {
            $important = ' !important';
            $str_value = CSSTidy::removeImportant($str_value, false);
        }

        $str_value = $this->explodeWs(',', $str_value);
        foreach ($str_value as $strVal) {
            $have = array(
                'clip' => false,
                'pos' => false,
                'color' => false,
                'bg' => false,
            );

            if (is_array($strVal)) {
                $strVal = $strVal[0];
            }

            $strVal = $this->explodeWs(' ', trim($strVal));

            foreach ($strVal as $current) {
                if ($have['bg'] === false && (substr($current, 0, 4) === 'url(' || $current === 'none')) {
                    $return['background-image'] .= $current . ',';
                    $have['bg'] = true;
                } else if (in_array($current, $repeat, true)) {
                    $return['background-repeat'] .= $current . ',';
                } else if (in_array($current, $attachment, true)) {
                    $return['background-attachment'] .= $current . ',';
                } else if (in_array($current, $clip, true) && !$have['clip']) {
                    $return['background-clip'] .= $current . ',';
                    $have['clip'] = true;
                } else if (in_array($current, $origin, true)) {
                    $return['background-origin'] .= $current . ',';
                } else if ($current{0} === '(') {
                    $return['background-size'] .= substr($current, 1, -1) . ',';
                } else if (in_array($current, $pos, true) || is_numeric($current{0}) || $current{0} === null || $current{0} === '-' || $current{0} === '.') {
                    $return['background-position'] .= $current . ($have['pos'] ? ',' : ' ');
                    $have['pos'] = true;
                } else if (!$have['color']) {
                    $return['background-color'] .= $current . ',';
                    $have['color'] = true;
                }
            }
        }

        foreach (self::$backgroundPropDefault as $backgroundProperty => $defaultValue) {
            if ($return[$backgroundProperty] !== null) {
                $return[$backgroundProperty] = substr($return[$backgroundProperty], 0, -1) . $important;
            } else {
                $return[$backgroundProperty] = $defaultValue . $important;
            }
        }

        return $return;
    }

    /**
     * Merges all background properties
     * @param array $inputCss
     * @return array
     * @version 1.0
     * @see dissolve_short_bg()
     * @todo full CSS 3 compliance
     */
    protected function mergeBackground(Block $block)
    {
        // Max number of background images. CSS3 not yet fully implemented
        $numberOfValues = @max(count($this->explodeWs(',', $block->properties['background-image'])), count($this->explodeWs(',', $block->properties['background-color'])), 1);
        // Array with background images to check if BG image exists
        $bg_img_array = @$this->explodeWs(',', CSSTidy::removeImportant($block->properties['background-image']));
        $newBackgroundValue = '';
        $important = '';

        // if background properties is here and not empty, don't try anything
        if (isset($block->properties['background']) && $block->properties['background']) {
            return $block->properties;
        }
        
        for ($i = 0; $i < $numberOfValues; $i++) {
            foreach (self::$backgroundPropDefault as $bg_property => $defaultValue) {
                // Skip if property does not exist
                if (!isset($block->properties[$bg_property])) {
                    continue;
                }

                $currentValue = $block->properties[$bg_property];
                // skip all optimisation if gradient() somewhere
                if (stripos($currentValue, "gradient(") !== false) {
                    return $block->properties;
                }

                // Skip some properties if there is no background image
                if ((!isset($bg_img_array[$i]) || $bg_img_array[$i] === 'none')
                                && ($bg_property === 'background-size' || $bg_property === 'background-position'
                                || $bg_property === 'background-attachment' || $bg_property === 'background-repeat')) {
                    continue;
                }

                // Remove !important
                if (CSSTidy::isImportant($currentValue)) {
                    $important = ' !important';
                    $currentValue = CSSTidy::removeImportant($currentValue, false);
                }

                // Do not add default values
                if ($currentValue === $defaultValue) {
                    continue;
                }

                $temp = $this->explodeWs(',', $currentValue);

                if (isset($temp[$i])) {
                    if ($bg_property === 'background-size') {
                        $newBackgroundValue .= '(' . $temp[$i] . ') ';
                    } else {
                        $newBackgroundValue .= $temp[$i] . ' ';
                    }
                }
            }

            $newBackgroundValue = trim($newBackgroundValue);
            if ($i != $numberOfValues - 1) {
                $newBackgroundValue .= ',';
            }
        }

        // Delete all background-properties
        foreach (self::$backgroundPropDefault as $bg_property => $foo) {
            unset($block->properties[$bg_property]);
        }

        // Add new background property
        if ($newBackgroundValue !== '') {
            $block->properties['background'] = $newBackgroundValue . $important;
        } else if (isset($block->properties['background'])) {
            $block->properties['background'] = 'none';
        }
    }

    /**
     * Dissolve font property
     * @param string $value
     * @return array
     * @version 1.3
     * @see merge_font()
     */
    protected function dissolveShortFont($value)
    {
        static $fontWeight = array('normal', 'bold', 'bolder', 'lighter', 100, 200, 300, 400, 500, 600, 700, 800, 900);
        static $fontVariant = array('normal', 'small-caps');
        static $fontStyle = array('normal', 'italic', 'oblique');

        $important = '';
        if (CSSTidy::isImportant($value)) {
            $important = '!important';
            $value = CSSTidy::removeImportant($value, false);
        }

        $return = array(
            'font-style' => null,
            'font-variant' => null,
            'font-weight' => null,
            'font-size' => null,
            'line-height' => null,
            'font-family' => null
        );

        $have = array(
            'style' => false,
            'variant' => false,
            'weight' => false,
            'size' => false,
        );

        // Detects if font-family consists of several words w/o quotes
        $multiwords = false;

        // Workaround with multiple font-family
        $value = $this->explodeWs(',', trim($value));

        $beforeColon = array_shift($value);
        $beforeColon = $this->explodeWs(' ', trim($beforeColon));

        foreach ($beforeColon as $propertyValue) {
            if ($have['weight'] === false && in_array($propertyValue, $fontWeight, true)) {
                $return['font-weight'] = $propertyValue;
                $have['weight'] = true;
            } else if ($have['variant'] === false && in_array($propertyValue, $fontVariant)) {
                $return['font-variant'] = $propertyValue;
                $have['variant'] = true;
            } else if ($have['style'] === false && in_array($propertyValue, $fontStyle)) {
                $return['font-style'] = $propertyValue;
                $have['style'] = true;
            } else if ($have['size'] === false && (is_numeric($propertyValue{0}) || $propertyValue{0} === null || $propertyValue{0} === '.')) {
                $size = $this->explodeWs('/', trim($propertyValue));
                $return['font-size'] = $size[0];
                if (isset($size[1])) {
                    $return['line-height'] = $size[1];
                } else {
                    $return['line-height'] = ''; // don't add 'normal' !
                }
                $have['size'] = true;
            } else {
                if (isset($return['font-family'])) {
                    $return['font-family'] .= ' ' . $propertyValue;
                    $multiwords = true;
                } else {
                    $return['font-family'] = $propertyValue;
                }
            }
        }
        // add quotes if we have several words in font-family
        if ($multiwords !== false) {
            $return['font-family'] = '"' . $return['font-family'] . '"';
        }

        foreach ($value as $fontFamily) {
            $return['font-family'] .= ',' . trim($fontFamily);
        }

        // Fix for 100 and more font-size
        if ($have['size'] === false && isset($return['font-weight']) &&
                        is_numeric($return['font-weight']{0})) {
            $return['font-size'] = $return['font-weight'];
            unset($return['font-weight']);
        }

        foreach (self::$fontPropDefault as $fontProperty => $defaultValue) {
            if (isset($return[$fontProperty])) {
                $return[$fontProperty] = $return[$fontProperty] . $important;
            } else {
                $return[$fontProperty] = $defaultValue . $important;
            }
        }

        return $return;
    }

    /**
     * Merge font properties into font shorthand
     * @todo: refactor
     * @param Element $block
     */
    protected function mergeFont(Block $block)
    {
        $newFontValue = '';
        $important = '';
        $preserveFontVariant = false;

        // Skip if is font-size not set
        if (isset($block->properties['font-size'])) {
            foreach (self::$fontPropDefault as $fontProperty => $defaultValue) {

                // Skip if property does not exist
                if (!isset($block->properties[$fontProperty])) {
                    continue;
                }

                $currentValue = $block->properties[$fontProperty];

                /**
                 * Skip if default value is used or if font-variant property is not small-caps
                 * @see http://www.w3.org/TR/css3-fonts/#propdef-font
                */
                if ($currentValue === $defaultValue) {
                    continue;
                } else if ($fontProperty === 'font-variant' && $currentValue !== 'small-caps') {
                    $preserveFontVariant = true;
                    continue;
                }

                // Remove !important
                if (CSSTidy::isImportant($currentValue)) {
                    $important = '!important';
                    $currentValue = CSSTidy::removeImportant($currentValue, false);
                }

                $newFontValue .= $currentValue;

                if ($fontProperty === 'font-size' &&
                    isset($block->properties['line-height']) &&
                    $block->properties['line-height'] !== ''
                ) {
                    $newFontValue .= '/';
                } else {
                    $newFontValue .= ' ';
                }
            }

            $newFontValue = trim($newFontValue);

            if ($newFontValue !== '') {
                // Delete all font-properties
                foreach (self::$fontPropDefault as $fontProperty => $defaultValue) {
                    if (!($fontProperty === 'font-variant' && $preserveFontVariant) && $fontProperty !== 'font') {
                        unset($block->properties[$fontProperty]);
                    }
                }

                // Add new font property
                $block->properties['font'] = $newFontValue . $important;
            }
        }
    }

    /**
     * Compress color inside gradient definition
     * @param string $string
     * @return string
     */
    protected function optimizeGradients($string)
    {
        /*
         * Gradient functions and color start from
         * -webkit-gradient syntax is not supported, because is deprecated
         */
        static $supportedGradients = array(
            'repeating-linear-gradient' => 1,
            'linear-gradient' => 1,
            'repeating-radial-gradient' => 2,
            'radial-gradient' => 2,
        );

        $originalType = strstr($string, '(', true);
        $type = $this->removeVendorPrefix($originalType);

        if ($type === false || !isset($supportedGradients[$type])) {
            return $string; // value is not gradient or unsupported type
        }

        $string = substr($string, strlen($originalType) + 1, -1); // Remove linear-gradient()
        $parts = $this->explodeWs(',', $string);

        $start = $supportedGradients[$type];
        foreach ($parts as $i => &$part) {
            if ($i < $start) {
                continue;
            }

            $colorAndLength = $this->explodeWs(' ', $part);
            $colorAndLength[0] = $this->optimiseColor->optimise($colorAndLength[0]);
            $part = implode(' ', $colorAndLength);
        }

        return "$originalType(" . implode(',', $parts) . ')';
    }

    /**
     * Optimize calc(), min(), max()
     *
     * @see http://www.w3.org/TR/css3-values/#calc
     * @param string $string
     * @return string
     */
    protected function optimizeCalc($string)
    {
        static $supportedTypes = array('min' => true, 'max' => true, 'calc' => true);

        $type = strstr($string, '(', true);

        if ($type === false || !isset($supportedTypes[$type])) {
            return $string;
        }

        $string = substr($string, strlen($type) + 1, -1); // Remove calc()
        $parts = $this->explodeWs(',', $string);

        foreach ($parts as &$part) {
            $part = str_replace(' ', '', $part);
        }

        return "$type(" . implode(',', $parts) . ')';
    }

    /**
     * @param $string
     * @return string
     */
    protected function optimizeTransform($string)
    {
        static $supportedTypes = array(
            'perspective' => true,
            'matrix' => true,
            'matrix3d' => true,
            'translate' => true,
            'translate3d' => true,
            'translateX' => true,
            'translateY' => true,
            'translateZ' => true,
            'scale3d' => true,
            'scaleX' => true,
            'scaleY' => true,
            'scaleZ' => true,
            'rotate3d' => true,
            'rotateX' => true,
            'rotateY' => true,
            'rotateZ' => true,
            'rotate' => true,
            'skewX' => true,
            'skewY' => true,
            'skew' => true,
        );

        $functions = $this->explodeWs(' ', $string);

        $output = array();
        foreach ($functions as $function) {
            $type = strstr($function, '(', true);

            if ($type === false || !isset($supportedTypes[$type])) {
                $output[] = $function;
                continue;
            }

            $function = substr($function, strlen($type) + 1, -1); // Remove function()
            $parts = $this->explodeWs(',', $function);

            foreach ($parts as &$part) {
                $part = $this->optimiseNumber->optimise(null, $part);
            }

            $output[$type] = implode(',', $parts);
        }

        // 3D transform
        foreach (array('scale', 'translate') as $mergeFunction) {
            if (isset($output[$mergeFunction . 'X']) && isset($output[$mergeFunction . 'Y']) && isset($output[$mergeFunction . 'Z'])) {
                $output[$mergeFunction . '3d'] = "{$output[$mergeFunction . 'X']},{$output[$mergeFunction . 'Y']},{$output[$mergeFunction . 'Z']}";
                unset($output[$mergeFunction . 'X'], $output[$mergeFunction . 'Y'], $output[$mergeFunction . 'Z']);
            }
        }

        // 2D transform
        foreach (array('skew', 'scale', 'translate', 'rotate') as $mergeFunction) {
            if (isset($output[$mergeFunction . 'X']) && isset($output[$mergeFunction . 'Y'])) {
                $output[$mergeFunction] = "{$output[$mergeFunction . 'X']},{$output[$mergeFunction . 'Y']}";
                unset($output[$mergeFunction . 'X'], $output[$mergeFunction . 'Y']);
            }
        }

        $outputString = '';
        foreach ($output as $name => $value) {
            if (is_numeric($name)) {
                $outputString .= $value . ' ';
            } else {
                $outputString .= "$name($value) ";
            }
        }

        return rtrim($outputString);
    }

     /**
     * Explodes a string as explode() does, however, not if $sep is escaped or within a string.
     * @param string $sep separator
     * @param string $string
     * @return array
     */
    protected function explodeWs($sep, $string)
    {
        if ($string === '' || $string === $sep) {
            return array();
        }

        $insideString = false;
        $to = '';
        $output = array(0 => '');
        $num = 0;

        for ($i = 0, $len = strlen($string); $i < $len; $i++) {
            if ($insideString) {
                if ($string{$i} === $to && !CSSTidy::escaped($string, $i)) {
                    $insideString = false;
                }
            } else {
                if ($string{$i} === $sep && !CSSTidy::escaped($string, $i)) {
                    ++$num;
                    $output[$num] = '';
                    continue;
                } else if ($string{$i} === '"' || $string{$i} === '\'' || $string{$i} === '(' && !CSSTidy::escaped($string, $i)) {
                    $insideString = true;
                    $to = ($string{$i} === '(') ? ')' : $string{$i};
                }
            }

            $output[$num] .= $string{$i};
        }

        return $output;
    }

    /**
     * @param string $string
     * @return string
     */
    protected function removeVendorPrefix($string)
    {
        if ($string{0} === '-') {
            $pos = strpos($string, '-', 1);
            return substr($string, $pos + 1);
        }

        return $string;
    }
}