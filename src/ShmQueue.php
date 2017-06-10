<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/31
 * Time: 下午8:08
 */

namespace inhere\queue;

use inhere\library\helpers\PhpHelper;
use inhere\shm\ShmMap;

/**
 * Class ShmQueue - shared memory queue
 * @package inhere\queue
 */
class ShmQueue extends BaseQueue
{
    /**
     * @var string
     */
    protected $driver = QueueFactory::DRIVER_SHM;

    /**
     * @var ShmMap[]
     */
    private $queues = [];

    /**
     * shm options
     * @var array
     */
    private $options = [
        'size' => 256000,
        'project' => 'php_shm', // shared memory, semaphore
        'tmpDir' => '/tmp', // tmp path
    ];

    /**
     * {@inheritDoc}
     */
    protected function init()
    {
        parent::init();

        if ($this->id <= 0) {
            // 定义共享内存,信号量key
            $this->id = PhpHelper::ftok(__FILE__, $this->options['project']);
        }

        // create queues
        $this->queues = new \SplFixedArray(count($this->getPriorities()));
    }

    /**
     * @param $data
     * @param int $priority
     * @return bool
     */
    protected function doPush($data, $priority = self::PRIORITY_NORM)
    {
        if (!$this->isPriority($priority)) {
            $priority = self::PRIORITY_NORM;
        }

        $this->createQueue($priority);

        return $this->queues[$priority]->push($this->encode($data));
    }

    /**
     * {@inheritDoc}
     */
    protected function doPop($priority = null, $block = false)
    {
        // 只想取出一个 $priority 队列的数据
        if ($this->isPriority($priority)) {
            $this->createQueue($priority);
            $data = $this->queues[$priority]->pop();

            return $this->decode($data);
        }

        $data = null;

        foreach ($this->queues as $queue) {
            $this->createQueue($priority);

            if (false !== ($data = $queue->pop())) {
                $data = $this->decode($data);
                break;
            }
        }

        // reset($this->queues);
        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        parent::close();

        foreach ($this->queues as $key => $queue) {
            if ($queue) {
                $queue->close();
                $this->queues[$key] = null;
            }
        }
    }

    /**
     * @param int $priority
     */
    protected function createQueue($priority)
    {
        if (!$this->queues[$priority]) {
            $config = $this->getOptions();
            $config['key'] = $this->intChannels[$priority];
            $this->queues[$priority] = new ShmMap($config);
        }
    }

    /**
     * @return ShmMap[]
     */
    public function getQueues()
    {
        return $this->queues;
    }

    /**
     * @param int $priority
     * @return ShmMap|false
     */
    public function getQueue($priority = self::PRIORITY_NORM)
    {
        if (!isset($this->getPriorities()[$priority])) {
            return false;
        }

        return $this->queues[$priority];
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->options = array_merge($this->options, $options);
    }
}
