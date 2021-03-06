<?php
namespace he\queue\tool;

use he\queue\Resque;
use he\queue\tool\job\DontPerform;
use he\queue\tool\job\Factory;
use he\queue\tool\job\FactoryInterface;
use he\queue\tool\job\Status;
use InvalidArgumentException;

class Job
{
	/**
	 * @var string The name of the queue that this job belongs to.
	 */
	public $queue;

	/**
	 * @var Worker Instance of the Resque worker running this job.
	 */
	public $worker;

	/**
	 * @var array Array containing details of the job.
	 */
	public $payload;

	/**
	 * @var object|JobInterface Instance of the class performing work for this job.
	 */
	private $instance;

	/**
	 * @var FactoryInterface
	 */
	private $jobFactory;

    /**
     * Instantiate a new instance of a job.
     *
     * @param string $queue The queue that the job belongs to.
     * @param array $payload array containing details of the job.
     */
	public function __construct(string $queue, array $payload)
	{
		$this->queue = $queue;
		$this->payload = $payload;
	}

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param null $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $monitor Set to true to be able to monitor the status of a job.
     * @param null $id Unique identifier for tracking the job. Generated if not supplied.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
	public static function create(string $queue, string $class, $args = null, $monitor = false, $id = null)
    {
		if (is_null($id)) {
			$id = Resque::generateJobId();
		}

		if($args !== null && !is_array($args)) {
			throw new InvalidArgumentException(
				'Supplied $args must be an array.'
			);
		}
		Resque::push($queue, array(
			'class'	=> $class,
			'args'	=> array($args),
			'id'	=> $id,
			'queue_time' => microtime(true),
		));

		if($monitor) {
			Status::create($id);
		}

		return $id;
	}

	/**
	 * Find the next available job from the specified queue and return an
	 * instance of Resque_Job for it.
	 *
	 * @param string $queue The name of the queue to check for a job in.
	 * @return false|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
	 */
	public static function reserve(string $queue)
	{
		$payload = Resque::pop($queue);
		if(!is_array($payload)) {
			return false;
		}

		return new Job($queue, $payload);
	}

	/**
	 * Find the next available job from the specified queues using blocking list pop
	 * and return an instance of Resque_Job for it.
	 *
	 * @param array             $queues
	 * @param int               $timeout
	 * @return false|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
	 */
	public static function reserveBlocking(array $queues, $timeout = null)
	{
		$item = Resque::blpop($queues, $timeout);

		if(!is_array($item)) {
			return false;
		}

		return new Job($item['queue'], $item['payload']);
	}

	/**
	 * Update the status of the current job.
	 *
	 * @param int $status Status constant from Resque_Job_Status indicating the current status of a job.
	 */
	public function updateStatus(int $status)
	{
		if(empty($this->payload['id'])) {
			return;
		}

		$statusInstance = new Status($this->payload['id']);
		$statusInstance->update($status);
	}

	/**
	 * Return the status of the current job.
	 *
	 * @return int The status of the job as one of the Resque_Job_Status constants.
	 */
	public function getStatus(): int
    {
		$status = new Status($this->payload['id']);
		return $status->get();
	}

	/**
	 * Get the arguments supplied to this job.
	 *
	 * @return array Array of arguments.
	 */
	public function getArguments(): array
    {
		if (!isset($this->payload['args'])) {
			return array();
		}

		return $this->payload['args'][0];
	}

	/**
	 * Get the instantiated object for this job that will be performing work.
	 * @return JobInterface Instance of the object that this job belongs to.
	 */
	public function getInstance(): JobInterface
    {
		if (!is_null($this->instance)) {
			return $this->instance;
		}

        $this->instance = $this->getJobFactory()->create($this->payload['class'], $this->getArguments(), $this->queue);
        $this->instance->job = $this;
        return $this->instance;
	}

	/**
	 * Actually execute a job by calling the perform method on the class
	 * associated with the job with the supplied arguments.
	 *
	 * @return bool
	 */
	public function perform(): bool
    {
		try {
			Event::trigger('beforePerform', $this);

			$instance = $this->getInstance();
			if(method_exists($instance, 'setUp')) {
				$instance->setUp();
			}

			$instance->perform();

			if(method_exists($instance, 'tearDown')) {
				$instance->tearDown();
			}

			Event::trigger('afterPerform', $this);
		}
		// beforePerform/setUp have said don't perform this job. Return.
		catch(DontPerform $e) {
			return false;
		}

		return true;
	}

	/**
	 * Mark the current job as having failed.
	 *
	 * @param $exception
	 */
	public function fail($exception)
	{
		Event::trigger('onFailure', array(
			'exception' => $exception,
			'job' => $this,
		));

		$this->updateStatus(Status::STATUS_FAILED);
		Failure::create(
			$this->payload,
			$exception,
			$this->worker,
			$this->queue
		);
		Stat::incr('failed');
		Stat::incr('failed:' . $this->worker);
	}

	/**
	 * Re-queue the current job.
	 * @return string
	 */
	public function recreate(): ?string
    {
		$status = new Status($this->payload['id']);
		$monitor = false;
		if($status->isTracking()) {
			$monitor = true;
		}

		return self::create($this->queue, $this->payload['class'], $this->getArguments(), $monitor);
	}

	/**
	 * Generate a string representation used to describe the current job.
	 *
	 * @return string The string representation of the job.
	 */
	public function __toString(): string
    {
        if (empty($this->payload)) {
            return '';
        }
		$name = array(
			'Job{' . $this->queue .'}'
		);
		if(!empty($this->payload['id'])) {
			$name[] = 'ID: ' . $this->payload['id'];
		}
		$name[] = $this->payload['class'];
		if(!empty($this->payload['args'])) {
			$name[] = json_encode($this->payload['args']);
		}
		return '(' . implode(' | ', $name) . ')';
	}


	public function setJobFactory(FactoryInterface $jobFactory): Job
    {
		$this->jobFactory = $jobFactory;

		return $this;
	}


    public function getJobFactory()
    {
        if ($this->jobFactory === null) {
            $this->jobFactory = new Factory();
        }
        return $this->jobFactory;
    }
}
