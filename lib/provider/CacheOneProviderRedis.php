<?php /** @noinspection ReturnTypeCanBeDeclaredInspection */
/** @noinspection PhpMissingParamTypeInspection */

/** @noinspection PhpComposerExtensionStubsInspection */

namespace eftec\provider;

use eftec\CacheOne;
use Exception;
use Redis;
use RuntimeException;

class CacheOneProviderRedis implements ICacheOneProvider
{
    /** @var null|\Redis */
    private $redis = null;
    /** @var null|CacheOne */
    private $parent = null;

    /**
     * AbstractCacheOneRedis constructor.
     *
     * @param CacheOne $parent
     * @param string   $server
     * @param string   $schema
     * @param int      $port
     * @param int      $timeout
     * @param null     $retry
     * @param null     $readTimeout
     */
    public function __construct(
        $parent,
        $server = '127.0.0.1',
        $schema = "",
        $port = 0,
        $timeout = 8,
        $retry = null,
        $readTimeout = null
    ) {
        $this->parent = $parent;

        $this->redis = new Redis();
        $port = (!$port) ? 6379 : $port;
        try {
            $r = @$this->redis->pconnect($server, $port, $timeout, null, $retry, $readTimeout);
        } catch (Exception $e) {
            $this->redis = null;
            $this->parent->enabled = false;
            return;
        }
        if ($r === false) {
            $this->redis = null;
            $this->parent->enabled = false;
            return;
        }

        $this->parent->schema = $schema;
        $this->parent->enabled = true;
    }

    public function invalidateGroup($group)
    {
        $numDelete = 0;
        if ($this->redis !== null) {
            foreach ($group as $nameGroup) {
                $guid = $this->parent->genCatId($nameGroup);
                $cdumplist = $this->parent->unserialize(@$this->redis->get($guid)); // it reads the catalog
                $cdumplist = (is_object($cdumplist)) ? (array)$cdumplist : $cdumplist;
                if (is_array($cdumplist)) {
                    $keys = array_keys($cdumplist);
                    foreach ($keys as $key) {
                        $numDelete += @$this->redis->del($key);
                    }
                }
                @$this->redis->del($guid); // delete the catalog
            }
        }
        return $numDelete > 0;
    }

    public function invalidateAll()
    {
        if ($this->redis === null) {
            return false;
        }
        return $this->redis->flushDB();
    }

    public function get($group, $key, $defaultValue = false)
    {
        $uid = $this->parent->genId($group, $key);
        $r = $this->parent->unserialize($this->redis->get($uid));
        return $r === false ? $defaultValue : $r;
    }

    public function set($groupID, $uid, $groups, $key, $value, $duration = 1440)
    {
        if ($groupID !== '') {
            foreach ($groups as $group) {
                $catUid = $this->parent->genCatId($group);
                $cat = $this->parent->unserialize(@$this->redis->get($catUid));
                $cat = (is_object($cat)) ? (array)$cat : $cat;
                if ($cat === false) {
                    $cat = array(); // created a new catalog
                }
                if (time() % 100 === 0) {
                    // garbage collector of the catalog. We run it around every 20th reads.
                    $keys = array_keys($cat);
                    foreach ($keys as $keyf) {
                        if (!$this->redis->exists($keyf)) {
                            unset($cat[$keyf]);
                        }
                    }
                }
                $cat[$uid] = 1; // we added/updated the catalog
                $catDuration = (($duration === 0 || $duration > $this->parent->catDuration)
                    && $this->parent->catDuration !== 0)
                    ? $duration : $this->parent->catDuration;
                @$this->redis->set($catUid, $this->parent->serialize($cat), $catDuration); // we store the catalog back.
            }
        }
        if ($duration === 0) {
            return $this->redis->set($uid, $this->parent->serialize($value)); // infinite duration
        }
        return $this->redis->set($uid, $this->parent->serialize($value), $duration);
    }

    public function invalidate($group = '', $key = '')
    {
        $uid = $this->parent->genId($group, $key);
        if ($this->redis === null) {
            return false;
        }
        $num = $this->redis->del($uid);
        return ($num > 0);
    }
    
    public function select($dbindex) {
        $this->redis->select($dbindex);
    }

}