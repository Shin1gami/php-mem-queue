<?php

namespace common\components\daemon;

/**
 * Singleton MessageQueue
 * @package common\components\daemon
 * @version 1.0.0
 * @author Shin1gami
 * @see https://stackoverflow.com/questions/39976805/how-to-properly-use-php5-semaphores
 */
class MessageQueue
{
    /**
     * @var resource $shared
     */
    protected $shared;

    /**
     * @var array $channels
     */
    protected $channels = [];

    /**
     * @var MessageQueue $instance
     */
    private static $instance;

    /**
     * @return MessageQueue
     */
    public static function getInstance()
    {
        return static::$instance !== null ? static::$instance : new static;
    }

    /**
     * MessageQueue constructor.
     */
    public function __construct()
    {
        static::$instance = $this;
    }

    /**
     * @void
     */
    public function __destruct()
    {
        foreach ($this->channels as $channel) {
            sem_release($channel['sem']);
            sem_remove($channel['sem']);
        }

        shm_remove($this->shared);
        shm_detach($this->shared);
    }

    /**
     * @overide
     * @void
     */
    public function __clone()
    {
    }

    /**
     * @overide
     * @void
     */
    public function __wakeup()
    {
    }

    /**
     * @param mixed $segment_id
     * @param int $alloc_size
     */
    public function allocate($segment_id, $alloc_size)
    {
        $segment_id = is_numeric($segment_id)
            ? $segment_id
            : $this->ikey($segment_id);

        $this->shared = shm_attach($segment_id, $alloc_size);
    }

    /**
     * @param string $channel
     */
    public function registerChannel($channel)
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [
                'sem' => sem_get($this->ikey($channel))
            ];
        }
    }

    /**
     * @param string $channel
     * @return \Serializable|null
     */
    public function next($channel)
    {
        return $this->mutex($channel, function ($channel) {
            if (!($data = $this->getVar($channel))) {
                return null;
            }

            $message = array_shift($data);
            $written = $this->setVar($channel, $data);

            return $written ? $message : null;
        });
    }

    /**
     * @param string $channel
     * @param \Serializable $message
     * @return bool
     */
    public function write($channel, $message)
    {
        return $this->mutex($channel, function ($channel) use ($message) {
            if (!($data = $this->getVar($channel))) {
                $data = [];
            }
            $data[] = $message;

            return $this->setVar($channel, $data);
        }, false);
    }

    /**
     * @param string $channel
     * @param \Closure $callback
     * @param null $default
     * @return mixed|null
     */
    protected function mutex($channel, \Closure $callback, $default = null)
    {
        if (!isset($this->channels[$channel])) {
            return $default;
        }

        if (!sem_acquire($this->channels[$channel]['sem'])) {
            return $default;
        }

        $result = $callback($channel);
        sem_release($this->channels[$channel]['sem']);

        return $result;
    }

    /**
     * @param $val
     * @return string|null
     */
    protected function ikey($val)
    {
        return preg_replace(
            "/[^0-9]/",
            "",
            (preg_replace("/[^0-9]/", "", md5($val)) / 35676248) / 619876
        );
    }

    /**
     * @param string $name
     * @return bool
     */
    protected function hasVar($name)
    {
        return shm_has_var($this->shared, $name);
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    protected function getVar($name)
    {
        return $this->hasVar($name) ? shm_get_var($this->shared, $name) : null;
    }

    /**
     * @param string $name
     * @param mixed $val
     * @return bool
     */
    protected function setVar($name, $val)
    {
        return shm_put_var($this->shared, $name, $val);
    }
}