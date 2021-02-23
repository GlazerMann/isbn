<?php

/*
 * This file is part of the biblys/isbn package.
 *
 * (c) Clément Bourgoin
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

namespace Biblys\Isbn;

class Isbn
{
    // Error messages (for localization)
    const ERROR_EMPTY = 'No code provided',
          ERROR_INVALID_CHARACTERS = 'Invalid characters in the code',
          ERROR_INVALID_LENGTH = 'Code is too short or too long',
          ERROR_INVALID_PRODUCT_CODE = 'Product code should be 978 or 979',
          ERROR_INVALID_COUNTRY_CODE = 'Country code is unknown';

    // GS1 Product Code (978 or 979 for books)
    private $_product;
    // Registrant group (country) code
    private $_country;
    // Registrant (publisher) Code
    private $_publisher;
    // Publication code
    private $_publication;
    // Checksum character
    private $_checksum;
    // Prefix for GTIN-14 formatting
    private $_gtin14Prefix;
    // Input code
    private $_input;
    // Is the code a valid ISBN
    private $_isValid = true;
    // Why is the code invalid
    private $_errors = array();
    // Output format
    private $_format = 'EAN';
    // XML ranges file prefixes
    private $_prefixes;
    // XML ranges file groups
    private $_groups;
    // ISBN Agency
    private $_agency;

    /**
     * @var Ranges
     */
    private $ranges = null;

    public function __construct($code = null)
    {
        $this->_input = $code;

        // If input is empty
        if (empty($code)) {
            $this->addError(static::ERROR_EMPTY);
            $this->setValid(false);
            return;
        }

        // Remove hyphens and check characters
        $code = $this->removeHyphens($code);

        // Remove checksum and check length
        $code = $this->removeChecksum($code);

        // At that point, code should be digits only
        if (!is_numeric($code)) {
            $this->setValid(false);
            $this->addError(static::ERROR_INVALID_CHARACTERS);
        }

        // Remove (and save) product code
        $code = $this->removeProductCode($code);

        // Remove (and save) country code
        $code = $this->removeCountryCode($code);

        // Remove (and save) publisher code
        $this->removePublisherCode($code);
    }

    /**
     * Gets a class that knows about the ISBN ranges
     *
     * @return Ranges
     */
    public function getRanges()
    {
        if ($this->ranges !== null) {
            return $this->ranges;
        }
        return $this->ranges = new Ranges();
    }

    /* Check methods */

    /**
     * Check if ISBN is valid
     */
    public function isValid()
    {
        return (bool) $this->_isValid;
    }

    /* Format methods */

    /**
     * Format an ISBN according to specified format
     * @param string $format (ISBN-10, ISBN-13, EAN, GTIN-14)
     */
    public function format($format = 'EAN', $prefix = 1)
    {
        if (!$this->isValid()) {
            throw new \Exception('Cannot format invalid ISBN: '.$this->getErrors());
        }

        if ($format == 'GTIN-14') {
            $this->setGtin14Prefix($prefix);
        } else {
            $this->setGtin14Prefix(NULL);
        }

        $this->calculateChecksum($format);

        $A = $this->getGtin14Prefix();
        $B = $this->getProduct();
        $C = $this->getCountry();
        $D = $this->getPublisher();
        $E = $this->getPublication();
        $F = $this->getChecksum();

        switch ($format) {
            case 'ISBN-10':
                return "$C-$D-$E-$F";
            break;

            case 'ISBN-13':
            case 'ISBN':
                return "$B-$C-$D-$E-$F";
            break;

            case 'GTIN-14':
                return $A.$B.$C.$D.$E.$F;
            break;

            default:
                return $B.$C.$D.$E.$F;
            break;
        }
    }

    // Private methods

    /**
     * Set ISBN Validity
     */
    private function setValid($isValid)
    {
        $this->_isValid = (bool) $isValid;
    }

    /**
     * Add to error log
     */
    private function addError($error)
    {
        $this->_errors[] = (string) $error;
    }

    /**
     * Delete '-', '_' and ' '
     */
    private function removeHyphens($code)
    {
        // Remove Hyphens and others characters
        $replacements = array('-','_',' ');
        $code = str_replace($replacements, '', $code);

        return $code;
    }

    /**
     * Remove checksum character if present
     */
    private function removeChecksum($code)
    {
        $length = strlen($code);
        if ($length == 13 || $length == 10) {
            $code = substr_replace($code, "", -1);
            return $code;
        } elseif ($length == 12 || $length == 9) {
            return $code;
        } else {
            $this->setValid(false);
            $this->addError(static::ERROR_INVALID_LENGTH);
            return $code;
        }
    }

    /**
     * Remove first three characters if 978 or 979 and save Product Code
     */
    private function removeProductCode($code)
    {
        $first3 = substr($code, 0, 3);

        // For ISBN-10, product code is always 978
        if (strlen($code) == 9) {
            $this->setProduct(978);
        }

        // ISBN-13: check that product code is 978 or 979
        elseif ($first3 == 978 || $first3 == 979) {
            $this->setProduct($first3);
            $code = substr($code, 3);
        }

        // Product code is Invalid
        else {
            $this->setValid(false);
            $this->addError(static::ERROR_INVALID_PRODUCT_CODE);
        }

        return $code;
    }

    /**
     * Remove and save Country Code
     */
    private function removeCountryCode($code)
    {

        // Get the seven first digits
        $first7 = substr($code, 0, 7);

        // Select the right set of rules according to the product code
        foreach ($this->getRanges()->getPrefixes() as $p) {
            if ($p['Prefix'] == $this->getProduct()) {
                $rules = $p['Rules']['Rule'];
                break;
            }
        }

        // If product code was not found, cannot proceed
        if (empty($rules)) {
            return null;
        }

        // Select the right rule
        foreach ($rules as $r) {
            $ra = explode('-', $r['Range']);
            if ($first7 >= $ra[0] && $first7 <= $ra[1]) {
                $length = $r['Length'];
                break;
            }
        }

        // Country code is invalid
        if (!isset($length) || $length === "0") {
            $this->setValid(false);
            $this->addError(static::ERROR_INVALID_COUNTRY_CODE);
            return $code;
        }

        $this->setCountry(substr($code, 0, $length));
        $code = substr($code, $length);

        return $code;
    }

    /**
     * Remove and save Publisher Code and Publication Code
     */
    private function removePublisherCode($code)
    {
        // Get the seven first digits or less
        $first7 = substr($code, 0, 7);
        $codeLength = strlen($first7);

        // Select the right set of rules according to the agency (product + country code)
        foreach ($this->getRanges()->getGroups() as $g) {
            if ($g['Prefix'] <> $this->getProduct().'-'.$this->getCountry()) {
                continue;
            }

            $rules = $g['Rules']['Rule'];
            $this->setAgency($g['Agency']);

            // Select the right rule
            foreach ($rules as $rule) {

                // Get min and max value in range
                // and trim values to match code length
                $range = explode('-', $rule['Range']);
                $min = substr($range[0], 0, $codeLength);
                $max = substr($range[1], 0, $codeLength);

                // If first 7 digits is smaller than min
                // or greater than max, continue to next rule
                if ($first7 < $min || $first7 > $max) {
                    continue;
                }

                $length = $rule['Length'];
                $this->setPublisher(substr($code, 0, $length));
                $this->setPublication(substr($code, $length));
                break;
            }
            break;
        }
    }

    /**
     * Calculate checksum character
     */
    private function calculateChecksum($format = 'EAN')
    {
        $sum = null;

        if ($format == 'ISBN-10') {
            $code = $this->getCountry().$this->getPublisher().$this->getPublication();
            $c = str_split($code);
            $sum = (11 - (($c[0] * 10) + ($c[1] * 9) + ($c[2] * 8) + ($c[3] * 7) + ($c[4] * 6) + ($c[5] * 5) + ($c[6] * 4) + ($c[7] * 3) + ($c[8] * 2)) % 11) % 11;
            if ($sum == 10) {
                $sum = 'X';
            }
        } else {
            $code = $this->getGtin14Prefix().$this->getProduct().$this->getCountry().$this->getPublisher().$this->getPublication();
            $c = array_reverse(str_split($code));

            foreach ($c as $k => $v) {
                if ($k & 1) { // If current array key is odd
                    $sum += $v;
                } else { // If current array key is even
                    $sum += $v * 3;
                }
            }

            $sum = (10 - ($sum % 10)) % 10;
        }

        $this->setChecksum($sum);
    }


    /* SETTERS */

    private function setProduct($product)
    {
        $this->_product = $product;
    }

    private function setCountry($country)
    {
        $this->_country = $country;
    }

    private function setPublisher($publisher)
    {
        $this->_publisher = $publisher;
    }

    private function setPublication($publication)
    {
        $this->_publication = $publication;
    }

    private function setChecksum($checksum)
    {
        $this->_checksum = $checksum;
    }

    private function setAgency($agency)
    {
        $this->_agency = $agency;
    }

    private function setGtin14Prefix($prefix)
    {
        $this->_gtin14Prefix = $prefix;
    }

    /* GETTERS */

    public function getProduct()
    {
        return $this->_product;
    }

    public function getCountry()
    {
        return $this->_country;
    }

    public function getPublisher()
    {
        return $this->_publisher;
    }

    public function getPublication()
    {
        return $this->_publication;
    }

    public function getChecksum()
    {
        return $this->_checksum;
    }

    public function getAgency()
    {
        return $this->_agency;
    }

    public function getGtin14Prefix()
    {
        return $this->_gtin14Prefix;
    }

    public function getErrors()
    {
        $errors = '['.$this->_input.']';
        foreach ($this->_errors as $e) {
            $errors .= ' '.$e;
        }
        return $errors;
    }

    public function validate()
    {
        $errors = $this->_errors;
        if ($errors) {
            throw new \Exception($errors[0]);
        }

        return true;
    }
}
