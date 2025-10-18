<?php


namespace Exodus4D\Socket\Data;


/**
 * Class Payload
 * @package Exodus4D\Socket\Data
 * @property string $task
 * @property mixed $load
 */
class Payload implements \JsonSerializable {

    /**
     * error message for missing 'task' name
     */
    const ERROR_TASK_MISSING        = "'task' must be a not empty string";

    /**
     * task name
     * @var string
     */
    private $task = '';

    /**
     * payload data
     * @var mixed
     */
    private $load;

    /**
     * optional characterId array -> recipients
     * -> e.g if multiple browser tabs are open
     * @var array<int>|null
     */
    private $characterIds;

    /**
     * Payload constructor.
     * @param string $task
     * @param null $load
     * @param array<int>|null $characterIds
     */
    public function __construct(string $task, $load = null, ?array $characterIds = null){
        $this->setTask($task);
        $this->setLoad($load);
        $this->setCharacterIds($characterIds);
    }

    /**
     * @param string $task
     */
    public function setTask(string $task) : void {
        if($task){
            $this->task = $task;
        }else{
            throw new \InvalidArgumentException(self::ERROR_TASK_MISSING);
        }
    }

    /**
     * @param null $load
     */
    public function setLoad($load = null) : void {
        $this->load = $load;
    }

    /**
     * @param array<int>|null $characterIds
     */
    public function setCharacterIds(?array $characterIds) : void {
        if(is_array($characterIds)){
            $this->characterIds = $characterIds;
        }else{
            $this->characterIds = null;
        }
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name){
        return $this->$name;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): mixed{
        return get_object_vars($this);
    }
}