<?php
/**
 * CSSTidy - CSS Parser and Optimiser
 *
 * CSS Printing class
 * This class prints CSS data generated by csstidy.
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
 * @package csstidy
 * @author Florian Schmitz (floele at gmail dot com) 2005-2007
 * @author Brett Zamir (brettz9 at yahoo dot com) 2007
 * @author Cedric Morin (cedric at yterium dot com) 2010
 * @author Jakub Onderka (acci at acci dot cz) 2011
 */
namespace CSSTidy;
/**
 * CSS Printing class
 *
 * This class prints CSS data generated by csstidy.
 *
 * @package csstidy
 * @author Florian Schmitz (floele at gmail dot com) 2005-2006
 * @version 1.0.1
 */
class Output
{
    const INPUT = 'input',
        OUTPUT = 'output';

	/**
	 * Saves the input CSS string
	 * @var string
	 */
	protected $inputCss;

	/**
	 * Saves the formatted CSS string
	 * @var string
	 */
	protected $outputCss;

	/**
	 * Saves the formatted CSS string (plain text)
	 * @var string
	 */
	protected $outputCssPlain;

    /** @var Configuration */
    protected $configuration;

    /** @var Logger */
    protected $logger;

    /** @var Parsed */
    protected $parsed;

    /**
     * @param Configuration $configuration
     * @param Logger $logger
     * @param string $inputCss
     * @param Parsed $parsed
     */
	public function __construct(Configuration $configuration, Logger $logger, $inputCss, Parsed $parsed)
    {
        $this->configuration = $configuration;
        $this->logger = $logger;
        $this->inputCss = $inputCss;
        $this->parsed = $parsed;
	}

	/**
	 * Returns the CSS code as plain text
	 * @param string $defaultMedia default @media to add to selectors without any @media
	 * @return string
	 * @access public
	 * @version 1.0
	 */
	public function plain($defaultMedia = null)
    {
		$this->generate(true, $defaultMedia);
		return $this->outputCssPlain;
	}

	/**
	 * Returns the formatted CSS code
	 * @param string $defaultMedia default @media to add to selectors without any @media
	 * @return string
	 * @access public
	 * @version 1.0
	 */
	public function formatted($defaultMedia = null)
    {
		$this->generate(false, $defaultMedia);
		return $this->outputCss;
	}

	/**
	 * Returns the formatted CSS code to make a complete webpage
	 * @param bool $externalCss indicates whether styles to be attached internally or as an external stylesheet
	 * @param string $title title to be added in the head of the document
	 * @return string
	 * @access public
	 * @version 1.4
	 */
	public function formattedPage($externalCss = true, $title = '')
    {
        if ($externalCss) {
			$css = "\n\n<style type=\"text/css\">\n";
			$cssParsed = file_get_contents('cssparsed.css');
			$css .= $cssParsed; // Adds an invisible BOM or something, but not in css_optimised.php
			$css .= "\n\n</style>";
		} else {
			$css = "\n\n" . '<link rel="stylesheet" type="text/css" href="cssparsed.css">';
		}

        return <<<html
<!DOCTYPE html>
<html>
    <head>
        <title>$title</title>
        $css
    </head>
    <body>
        <code id="copytext">{$this->formatted()}</code>
    </body>
</html>
html;
	}

    /**
	 * Get compression ratio
	 * @access public
	 * @return float
	 * @version 1.2
	 */
	public function getRatio()
    {
        $input = $this->size(self::INPUT);
        $output = $this->size(self::OUTPUT);

		return round(($input - $output) / $input, 3) * 100;
	}

	/**
	 * Get difference between the old and new code in bytes and prints the code if necessary.
	 * @access public
	 * @return string
	 * @version 1.1
	 */
	public function getDiff()
    {
		if (!$this->outputCssPlain) {
			$this->formatted();
		}

		$diff = strlen($this->outputCssPlain) - strlen($this->inputCss);

		if ($diff > 0) {
			return '+' . $diff;
		} elseif ($diff == 0) {
			return '+-' . $diff;
		}

		return $diff;
	}

	/**
	 * Get the size of either input or output CSS in KB
	 * @param string $loc default is "output"
	 * @return float
	 * @version 1.0
	 */
	public function size($loc = self::OUTPUT)
    {
		if ($loc === self::OUTPUT && !$this->outputCss) {
			$this->formatted();
		}

		if ($loc === self::INPUT) {
			return (strlen($this->inputCss) / 1000);
		} else {
			return (strlen($this->outputCssPlain) / 1000);
		}
	}

    /**
     * @param string $loc
     * @param int $level
     * @return float
     */
    public function gzippedSize($loc = self::OUTPUT, $level = -1)
    {
        if ($loc === self::OUTPUT && !$this->outputCss) {
			$this->formatted();
		}

		if ($loc === self::INPUT) {
			return (strlen(gzencode($this->inputCss, $level)) / 1000);
		} else {
			return (strlen(gzencode($this->outputCssPlain, $level)) / 1000);
		}
    }

	/**
	 * Returns the formatted CSS Code and saves it into $this->output_css and $this->output_css_plain
	 * @param bool $plain plain text or not
	 * @param string $defaultMedia default @media to add to selectors without any @media
	 * @version 2.0
	 */
	protected function generate($plain = false, $defaultMedia = null)
    {
		if ($this->outputCss && $this->outputCssPlain) {
			return;
		}

		if (!$this->configuration->getPreserveCss()) {
			$this->convertRawCss($defaultMedia);
		}

		$template = $this->configuration->getTemplate();

		if ($this->configuration->getAddTimestamp()) {
			array_unshift($this->parsed->tokens, array(CSSTidy::COMMENT, ' CSSTidy ' . CSSTidy::getVersion() . ': ' . date('r') . ' '));
		}

		if (!$plain) {
            $this->outputCss = $this->tokensToCss($template, false);
		}

        $template = array_map('strip_tags', $template);
        $this->outputCssPlain = $this->tokensToCss($template, true);

        // If using spaces in the template, don't want these to appear in the plain output
        $this->outputCssPlain = str_replace('&#160;', '', $this->outputCssPlain);
	}

    /**
     * @param array $template
     * @param bool $plain
     * @return string
     */
    protected function tokensToCss(array $template, $plain)
    {
        $output = '';

        if (!empty($this->parsed->charset)) {
			$output .= $template[0] . '@charset ' . $template[5] . $this->parsed->charset . $template[6];
		}

        foreach ($this->parsed->import as $import) {
            // Replace url('abc.css') with 'abc.css'
            $replaced = preg_replace('~url\(["\']?([^\)\'"]*)["\']?\)~', '"$1"', $import);
            if ($replaced !== $import) {
                $import = $replaced;
                $this->logger->log('Optimised @import: Removed "url("', 'Information');
            }

            $output .= $template[0] . '@import' . $template[5] . $import . $template[6];
        }

		if (!empty($this->parsed->namespace)) {
			if (substr($this->parsed->namespace, 0, 4) === 'url(' && substr($this->parsed->namespace, -1, 1) === ')') {
				$this->parsed->namespace = '\'' . substr($this->parsed->namespace, 4, -1) . '\'';
				$this->logger->log('Optimised @namespace: Removed "url("', 'Information');
			}
			$output .= $template[0] . '@namespace ' . $template[5] . $this->parsed->namespace . $template[6];
		}

		$output .= $template[13];
		$in_at_out = '';
		$out = & $output;

		foreach ($this->parsed->tokens as $key => $token) {
			switch ($token[0]) {
                case CSSTidy::PROPERTY:
                    if ($this->configuration->getCaseProperties() === Configuration::LOWERCASE) {
						$token[1] = strtolower($token[1]);
					} elseif ($this->configuration->getCaseProperties() === Configuration::UPPERCASE) {
						$token[1] = strtoupper($token[1]);
                    }
					$out .= $template[4] . $this->htmlsp($token[1], $plain) . ':' . $template[5];
					break;

				case CSSTidy::VALUE:
					$out .= $this->htmlsp($token[1], $plain);
					if ($this->seekNoComment($key, 1) === CSSTidy::SEL_END && $this->configuration->getRemoveLastSemicolon()) {
						$out .= str_replace(';', '', $template[6]);
					} else {
						$out .= $template[6];
					}
					break;

                case CSSTidy::SEL_START:
					if ($this->configuration->getLowerCaseSelectors()) {
						$token[1] = strtolower($token[1]);
                    }
					$out .= $template[ $token[1]{0} !== '@' ? 2 : 0 ] . $this->htmlsp($token[1], $plain) . $template[3];
					break;

				case CSSTidy::SEL_END:
					$out .= $template[7];
					if ($this->seekNoComment($key, 1) !== CSSTidy::AT_END) {
						$out .= $template[8];
                    }
					break;

				case CSSTidy::AT_START:
					$out .= $template[0] . $this->htmlsp($token[1], $plain) . $template[1];
					$out = & $in_at_out;
					break;

				case CSSTidy::AT_END:
					$out = & $output;
					$out .= $template[10] . str_replace("\n", "\n" . $template[10], $in_at_out);
					$in_at_out = '';
					$out .= $template[9];
					break;

				case CSSTidy::COMMENT:
					$out .= $template[11] . '/*' . $this->htmlsp($token[1], $plain) . '*/' . $template[12];
					break;
			}
		}

		return trim($output);
    }

    /**
	 * Gets the next token type which is $move away from $key, excluding comments
	 * @param integer $key current position
	 * @param integer $move move this far
	 * @return int a token type
	 * @version 1.0
	 */
	protected function seekNoComment($key, $move)
    {
		$go = ($move > 0) ? 1 : -1;
		for ($i = $key + 1; abs($key - $i) - 1 < abs($move); $i += $go) {
			if (!isset($this->parsed->tokens[$i])) {
				return;
			}

			if ($this->parsed->tokens[$i][0] === CSSTidy::COMMENT) {
				++$move;
				continue;
			}

			return $this->parsed->tokens[$i][0];
		}
	}

	/**
	 * Converts $this->css array to a raw array ($this->tokens)
	 * @param string $defaultMedia default @media to add to selectors without any @media
	 * @access private
	 * @version 1.0
	 */
	protected function convertRawCss($defaultMedia = '')
    {
		$this->parsed->tokens = array();

		foreach ($this->parsed->css as $medium => $val) {
			if ($this->configuration->getSortSelectors()) {
				ksort($val);
            }

			if ($medium < CSSTidy::DEFAULT_AT) {
				$this->parsed->addToken(CSSTidy::AT_START, $medium, true);
			} elseif ($defaultMedia) {
				$this->parsed->addToken(CSSTidy::AT_START, $defaultMedia, true);
			}
			
			foreach ($val as $selector => $vali) {
				if ($this->configuration->getSortProperties()) {
					ksort($vali);
                }
				$this->parsed->addToken(CSSTidy::SEL_START, $selector, true);

				foreach ($vali as $property => $valj) {
					$this->parsed->addToken(CSSTidy::PROPERTY, $property, true);
					$this->parsed->addToken(CSSTidy::VALUE, $valj, true);
				}

				$this->parsed->addToken(CSSTidy::SEL_END, $selector, true);
			}

			if ($medium < CSSTidy::DEFAULT_AT) {
				$this->parsed->addToken(CSSTidy::AT_END, $medium, true);
			} elseif ($defaultMedia) {
				$this->parsed->addToken(CSSTidy::AT_END, $defaultMedia, true);
			}
		}
	}

	/**
	 * Same as htmlspecialchars, only that chars are not replaced if $plain !== true. This makes  print_code() cleaner.
	 * @param string $string
	 * @param bool $plain
	 * @return string
	 * @see csstidy_print::_print()
	 * @access private
	 * @version 1.0
	 */
	protected  function htmlsp($string, $plain)
    {
		if (!$plain) {
			return htmlspecialchars($string, ENT_QUOTES, 'utf-8');
		}
		return $string;
	}
}