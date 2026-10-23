<?php
/**
 * HMAC verification for the internal TCP command channel.
 *
 * Mirrors Exodus4D\Pathfinder\Lib\Socket\CommandAuth (pathfinder repo):
 * both sides must implement the identical wire format:
 *   sig = HMAC-SHA256(secret, task . "\n" . ts . "\n" . nonce . "\n" . canonicalLoad)
 *   canonicalLoad = json_encode(load, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
 *
 * Envelope on the wire: payload = ['task' => ..., 'load' => ..., 'auth' => [...]]
 */

namespace Exodus4D\Socket\Socket;

class CommandAuth {

    /**
     * max accepted clock difference in seconds between sender and receiver
     */
    const TIMESTAMP_WINDOW = 120;

    /**
     * bound for the in-memory replay cache (oldest entries pruned first)
     */
    const MAX_NONCES = 5000;

    /**
     * seen nonces with expiry: nonce => expiresAt
     * @var array<string, int>
     */
    private static $seenNonces = [];

    /**
     * verify an incoming auth envelope (fail closed on any problem)
     * -> consumes the nonce: a second verify() of the same envelope fails
     * @param string $secret
     * @param string $task
     * @param mixed $load
     * @param mixed $auth
     * @return bool
     */
    public static function verify(string $secret, string $task, $load, $auth) : bool {
        if($secret === '' || !is_array($auth)){
            return false;
        }
        $ts = $auth['ts'] ?? null;
        $nonce = $auth['nonce'] ?? null;
        $sig = $auth['sig'] ?? null;
        if(!is_int($ts) && !(is_string($ts) && ctype_digit($ts))){
            return false;
        }
        if(!is_string($nonce) || !preg_match('/^[0-9a-f]{16,64}$/', $nonce)){
            return false;
        }
        if(!is_string($sig) || $sig === ''){
            return false;
        }
        if(abs(time() - (int)$ts) > self::TIMESTAMP_WINDOW){
            return false;
        }
        self::pruneNonces();
        if(isset(self::$seenNonces[$nonce])){
            return false;
        }
        $expected = self::signature($secret, $task, $load, (int)$ts, $nonce);
        if(!hash_equals($expected, strtolower($sig))){
            return false;
        }
        self::$seenNonces[$nonce] = time() + self::TIMESTAMP_WINDOW;
        if(count(self::$seenNonces) > self::MAX_NONCES){
            self::$seenNonces = array_slice(self::$seenNonces, -self::MAX_NONCES, null, true);
        }
        return true;
    }

    /**
     * canonical signature input (both repos must implement identically)
     * @param mixed $load
     */
    public static function signature(string $secret, string $task, mixed $load, int $ts, string $nonce) : string {
        return hash_hmac(
            'sha256',
            $task . "\n" . $ts . "\n" . $nonce . "\n" . self::canonicalLoad($load),
            $secret
        );
    }

    /**
     * @param mixed $load
     */
    public static function canonicalLoad(mixed $load) : string {
        $encoded = json_encode($load, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if($encoded === false){
            throw new \InvalidArgumentException('command load is not JSON encodable');
        }
        return $encoded;
    }

    private static function pruneNonces() : void {
        $now = time();
        foreach(self::$seenNonces as $nonce => $expires){
            if($expires < $now){
                unset(self::$seenNonces[$nonce]);
            }
        }
    }
}
