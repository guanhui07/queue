<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/21
 * Time: 上午1:45
 */

namespace inhere\queue;

/**
 * Class BaseQueue
 * @package inhere\queue
 */
abstract class BaseQueue implements QueueInterface
{
    /**
     * @var string
     */
    protected $driver;

    /**
     * The queue id(name)
     * @var string|int
     */
    protected $id;

    /**
     * @var int
     */
    protected $errCode = 0;

    /**
     * @var string
     */
    protected $errMsg;

    /**
     * @var array
     */
    protected $config = [
        'id' => null,
        'serialize' => true,
    ];

    /**
     * @var array
     */
    private $_events = [];

    /**
     * @var array
     */
    protected $channels = [];

    /**
     * @var array
     */
    protected $intChannels = [];

    /**
     * MsgQueue constructor.
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);

        $this->init();

        // init property
        $this->getChannels();
        $this->getIntChannels();
    }

    /**
     * init
     */
    protected function init()
    {
        $this->config['serialize'] = (bool)$this->config['serialize'];

        if (isset($this->config['id'])) {
            $this->id = $this->config['id'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function push($data, $priority = self::PRIORITY_NORM): bool
    {
        $status = false;
        $this->fire(self::EVENT_BEFORE_PUSH, [$data, $priority]);

        try {
            $status = $this->doPush($data, $priority);
        } catch (\Exception $e) {
            $this->errCode = $e->getCode() !== 0 ? $e->getCode() : __LINE__;
            $this->errMsg = $e->getMessage();

            $this->fire(self::EVENT_ERROR_PUSH, [$e, $this]);
        }

        if (0 === $this->errCode) {
            $this->fire(self::EVENT_AFTER_PUSH, [$data, $priority, $status]);
        }

        return $status;
    }

    /**
     * @param $data
     * @param int $priority
     * @return bool
     */
    abstract protected function doPush($data, $priority = self::PRIORITY_NORM);

    /**
     * {@inheritDoc}
     */
    public function pop($priority = null, $block = false)
    {
        $data = null;
        $this->fire(self::EVENT_BEFORE_POP, [$priority, $this]);

        try {
            $data = $this->doPop($priority, $block);
        } catch (\Exception $e) {
            $this->errCode = $e->getCode() !== 0 ? $e->getCode() : __LINE__;
            $this->errMsg = $e->getMessage();

            $this->fire(self::EVENT_ERROR_POP, [$e, $priority, $this]);
        }

        if (0 === $this->errCode) {
            $this->fire(self::EVENT_AFTER_POP, [$data, $priority, $this]);
        }

        return $data;
    }

    /**
     * @param null $priority
     * @param bool $block
     * @return mixed
     */
    abstract protected function doPop($priority = null, $block = false);

//////////////////////////////////////////////////////////////////////
/// events method
//////////////////////////////////////////////////////////////////////

    /**
     * register a event callback
     * @param string $name event name
     * @param callable $cb event callback
     * @param bool $replace replace exists's event cb
     * @return $this
     */
    public function on($name, callable $cb, $replace = false)
    {
        if ($replace || !isset($this->_events[$name])) {
            $this->_events[$name] = $cb;
        }

        return $this;
    }

    /**
     * @param string $name
     * @param array $args
     * @return mixed
     */
    protected function fire($name, array $args = [])
    {
        if (!isset($this->_events[$name]) || !($cb = $this->_events[$name])) {
            return null;
        }

        return call_user_func_array($cb, $args);
    }

    /**
     * @param $name
     * @return null|mixed
     */
    public function off($name)
    {
        $cb = null;

        if (isset($this->_events[$name])) {
            $cb = $this->_events[$name];
            unset($this->_events[$name]);
        }

        return $cb;
    }

//////////////////////////////////////////////////////////////////////
/// helper method
//////////////////////////////////////////////////////////////////////

    /**
     * get Priorities
     * @return array
     */
    public function getPriorities(): array
    {
        return [
            self::PRIORITY_HIGH,
            self::PRIORITY_NORM,
            self::PRIORITY_LOW,
        ];
    }

    /**
     * @param int $priority
     * @return bool
     */
    public function isPriority($priority)
    {
        if (null === $priority) {
            return false;
        }

        return in_array((int)$priority, $this->getPriorities(), true);
    }

    /**
     * @return array
     */
    public function getChannels()
    {
        if (!$this->channels) {
            $this->channels = [
                self::PRIORITY_HIGH => $this->id . self::PRIORITY_HIGH_SUFFIX,
                self::PRIORITY_NORM => $this->id,
                self::PRIORITY_LOW => $this->id . self::PRIORITY_LOW_SUFFIX,
            ];
        }

        return $this->channels;
    }

    /**
     * @return array
     */
    public function getIntChannels()
    {
        if (!$this->intChannels) {
            $id = (int)$this->id;
            $this->intChannels = [
                self::PRIORITY_HIGH => $id + self::PRIORITY_HIGH,
                self::PRIORITY_NORM => $id + self::PRIORITY_NORM,
                self::PRIORITY_LOW => $id + self::PRIORITY_LOW,
            ];
        }

        return $this->intChannels;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    protected function encode($data)
    {
        if (!$this->config['serialize']) {
            return $data;
        }

        return serialize($data);
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    protected function decode($data)
    {
        if (!$this->config['serialize']) {
            return $data;
        }

        return unserialize($data, null);
    }

    /**
     * __destruct
     */
    public function __destruct()
    {
        $this->close();
    }

//////////////////////////////////////////////////////////////////////
/// getter/setter method
//////////////////////////////////////////////////////////////////////

    /**
     * close
     */
    public function close()
    {
        $this->_events = [];
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return int
     */
    public function getErrCode(): int
    {
        return $this->errCode;
    }

    /**
     * @return string
     */
    public function getErrMsg(): string
    {
        return $this->errMsg;
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
    }
}
