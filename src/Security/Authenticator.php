<?php
declare(strict_types=1);

namespace OwnPay\Security;

use Exception;

/**
 * PHP Class for handling Google Authenticator 2-factor authentication.
 * Refactored for Own Pay Enterprise Security Standards.
 *
 * @author Michael Kliewe (original)
 * @copyright 2012 Michael Kliewe
 * @license http://www.opensource.org/licenses/bsd-license.php BSD License
 */
class Authenticator
{
    protected $_codeLength = 6;

    /**
     * Create new secret.
     * 16 characters, randomly chosen from the allowed base32 characters.
     *
     * @param int $secretLength
     *
     * @return string
     * @throws Exception
     */
    public function createSecret($secretLength = 16)
    {
        $validChars = $this->_getBase32LookupTable();

        // Valid secret lengths are 80 to 640 bits
        if ($secretLength < 16 || $secretLength > 128) {
            throw new Exception('Bad secret length');
        }
        $secret = '';

        try {
            $rnd = random_bytes($secretLength);
        } catch (Exception $e) {
            throw new Exception('No source of secure random');
        }

        for ($i = 0; $i < $secretLength; ++$i) {
            $secret .= $validChars[ord($rnd[$i]) & 31];
        }

        return $secret;
    }

    /**
     * Calculate the code, with given secret and point in time.
     *
     * @param string   $secret
     * @param int|null $timeSlice
     *
     * @return string
     */
    public function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = (int) floor(time() / 30);
        }

        $secretkey = $this->_base32Decode($secret);

        // Pack time into binary string
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
        // Hash it with users secret key
        $hm = hash_hmac('SHA1', $time, $secretkey, true);
        // Use last nipple of result as index/offset
        $offset = ord(substr($hm, -1)) & 0x0F;
        // grab 4 bytes of the result
        $hashpart = substr($hm, $offset, 4);

        // Unpak binary value
        $value = unpack('N', $hashpart);
        $value = $value[1];
        // Only 32 bits
        $value = $value & 0x7FFFFFFF;

        $modulo = pow(10, $this->_codeLength);

        return str_pad((string) ($value % $modulo), $this->_codeLength, '0', STR_PAD_LEFT);
    }

    /**
     * Check if the code is correct. This will accept codes starting from $discrepancy*30sec ago to $discrepancy*30sec from now.
     *
     * @param string   $secret
     * @param string   $code
     * @param int      $discrepancy      This is the allowed time drift in 30 second units (8 means 4 minutes before or after)
     * @param int|null $currentTimeSlice time slice if we want use other that time()
     *
     * @return bool
     */
    public function verifyCode($secret, $code, $discrepancy = 1, $currentTimeSlice = null)
    {
        if ($currentTimeSlice === null) {
            $currentTimeSlice = (int) floor(time() / 30);
        }

        if (strlen($code) !== 6) {
            return false;
        }

        for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify TOTP code with replay-prevention.
     *
     * F6 from docs/security_audit/full_codebase_audit.md
     *
     * Unlike verifyCode(), this method rejects any window <= $lastUsedWindow,
     * preventing the same code from being reused within the discrepancy window.
     *
     * Returns the matched window index (which the caller MUST persist to the
     * user's `last_otp_window` column), or -1 on failure / replay attempt.
     *
     * Usage:
     *   $matched = $authenticator->verifyCodeWithReplayGuard($secret, $code, $user['last_otp_window'] ?? 0);
     *   if ($matched < 0) { // reject login }
     *   else { UPDATE op_merchant_users SET last_otp_window = $matched WHERE id = ? }
     *
     * @param string $secret           base32-encoded TOTP secret
     * @param string $code             6-digit code from user
     * @param int    $lastUsedWindow   most recent successful window (0 if first verify)
     * @param int    $discrepancy      ± window range (default 2 = 60s drift tolerance)
     * @param int|null $currentTimeSlice override for testing
     * @return int                     matched window (>0) or -1 on failure / replay
     */
    public function verifyCodeWithReplayGuard(
        string $secret,
        string $code,
        int $lastUsedWindow,
        int $discrepancy = 2,
        ?int $currentTimeSlice = null,
    ): int {
        if ($currentTimeSlice === null) {
            $currentTimeSlice = (int) floor(time() / 30);
        }
        if (strlen($code) !== 6) {
            return -1;
        }
        for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
            $window = $currentTimeSlice + $i;
            // F6: reject any window already consumed
            if ($window <= $lastUsedWindow) {
                continue;
            }
            $calculatedCode = $this->getCode($secret, $window);
            if (hash_equals($calculatedCode, $code)) {
                return $window;
            }
        }
        return -1;
    }

    /**
     * Set the code length, should be >=6.
     *
     * @param int $length
     *
     * @return self
     */
    public function setCodeLength($length)
    {
        $this->_codeLength = $length;

        return $this;
    }

    /**
     * Helper class to decode base32.
     *
     * @param $secret
     *
     * @return bool|string
     */
    protected function _base32Decode($secret)
    {
        if (empty($secret)) {
            return '';
        }

        $base32chars = $this->_getBase32LookupTable();
        $base32charsFlipped = array_flip($base32chars);

        $paddingCharCount = substr_count($secret, $base32chars[32]);
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues, true)) {
            return false;
        }
        for ($i = 0; $i < 4; ++$i) {
            if (
                $paddingCharCount === $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) !== str_repeat($base32chars[32], $allowedValues[$i])
            ) {
                return false;
            }
        }
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        for ($i = 0, $count = count($secret); $i < $count; $i += 8) {
            $x = '';
            if (!in_array($secret[$i], $base32chars, true)) {
                return false;
            }
            for ($j = 0; $j < 8; ++$j) {
                // @-operator is generally bad practice, replace with isset()
                $char = $secret[$i + $j] ?? '';
                if (!isset($base32charsFlipped[$char])) {
                    $x .= str_pad('0', 5, '0', STR_PAD_LEFT);
                } else {
                    $x .= str_pad(base_convert((string) $base32charsFlipped[$char], 10, 2), 5, '0', STR_PAD_LEFT);
                }
            }
            $eightBits = str_split($x, 8);
            for ($z = 0, $count8 = count($eightBits); $z < $count8; ++$z) {
                $y = chr((int) base_convert($eightBits[$z], 2, 10));
                $binaryString .= ($y || ord($y) === 48) ? $y : '';
            }
        }

        return $binaryString;
    }

    /**
     * Get array with all 32 characters for decoding from/encoding to base32.
     *
     * @return array
     */
    protected function _getBase32LookupTable()
    {
        return array(
            'A',
            'B',
            'C',
            'D',
            'E',
            'F',
            'G',
            'H', //  7
            'I',
            'J',
            'K',
            'L',
            'M',
            'N',
            'O',
            'P', // 15
            'Q',
            'R',
            'S',
            'T',
            'U',
            'V',
            'W',
            'X', // 23
            'Y',
            'Z',
            '2',
            '3',
            '4',
            '5',
            '6',
            '7', // 31
            '=',  // padding char
        );
    }
}
