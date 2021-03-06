<?php
/*
 * Copyright (c) KUBO Atsuhiro <kubo@iteman.jp> and contributors,
 * All rights reserved.
 *
 * This file is part of Workflower.
 *
 * This program and the accompanying materials are made available under
 * the terms of the BSD 2-Clause License which accompanies this
 * distribution, and is available at http://opensource.org/licenses/BSD-2-Clause
 */

namespace PHPMentors\Workflower\Workflow;

use PHPMentors\Workflower\Workflow\Activity\ActivityInterface;
use PHPMentors\Workflower\Workflow\Activity\UnexpectedActivityException;
use PHPMentors\Workflower\Workflow\Connection\SequenceFlow;
use PHPMentors\Workflower\Workflow\Element\ConditionalInterface;
use PHPMentors\Workflower\Workflow\Element\ConnectingObjectCollection;
use PHPMentors\Workflower\Workflow\Element\ConnectingObjectInterface;
use PHPMentors\Workflower\Workflow\Element\FlowObjectCollection;
use PHPMentors\Workflower\Workflow\Element\FlowObjectInterface;
use PHPMentors\Workflower\Workflow\Element\TransitionalInterface;
use PHPMentors\Workflower\Workflow\Event\EndEvent;
use PHPMentors\Workflower\Workflow\Event\StartEvent;
use PHPMentors\Workflower\Workflow\Gateway\GatewayInterface;
use PHPMentors\Workflower\Workflow\Operation\OperationalInterface;
use PHPMentors\Workflower\Workflow\Operation\OperationRunnerInterface;
use PHPMentors\Workflower\Workflow\Participant\ParticipantInterface;
use PHPMentors\Workflower\Workflow\Participant\Role;
use PHPMentors\Workflower\Workflow\Participant\RoleCollection;
use Stagehand\FSM\State\FinalState;
use Stagehand\FSM\StateMachine\StateMachineBuilder;
use Stagehand\FSM\StateMachine\StateMachineInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class Workflow implements \Serializable
{
    const DEFAULT_ROLE_ID = '__ROLE__';

    /**
     * @var string
     */
    private static $STATE_START = '__START__';

    /**
     * @var int|string
     *
     * @since Property available since Release 2.0.0
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /**
     * @var ConnectingObjectCollection
     */
    private $connectingObjectCollection;

    /**
     * @var FlowObjectCollection
     */
    private $flowObjectCollection;

    /**
     * @var RoleCollection
     */
    private $roleCollection;

    /**
     * @var \DateTime
     */
    private $startDate;

    /**
     * @var \DateTime
     */
    private $endDate;

    /**
     * @var array
     */
    private $processData;

    /**
     * @var StateMachineInterface
     */
    private $stateMachine;

    /**
     * @var StateMachineBuilder
     *
     * @since Property available since Release 2.0.0
     */
    private $stateMachineBuilder;

    /**
     * @var ExpressionLanguage
     *
     * @since Property available since Release 1.1.0
     */
    private $expressionLanguage;

    /**
     * @var OperationRunnerInterface
     *
     * @since Property available since Release 1.2.0
     */
    private $operationRunner;

    /**
     * @param int|string $id
     * @param string     $name
     */
    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
        $this->connectingObjectCollection = new ConnectingObjectCollection();
        $this->flowObjectCollection = new FlowObjectCollection();
        $this->roleCollection = new RoleCollection();
        $this->stateMachineBuilder = $this->createStateMachineBuilder($this->id);
        $this->transaction = null;
    }

    /**
     * {@inheritdoc}
     */
    public function serialize($ignore = [])
    {
        $fields = [
                'name',
                'connectingObjectCollection',
                'flowObjectCollection',
                'roleCollection',
                'startDate',
                'endDate',
                'stateMachine',
            ];

        $data = [];
        foreach($fields as $field)
            if (!in_array($field, $ignore))
                $data[$field] = $this->{$field};

        return serialize($data);
    }

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized, $ignore = [])
    {
        foreach (unserialize($serialized) as $name => $value) {
            if (property_exists($this, $name) && !in_array($name, $ignore)) {
                $this->$name = $value;
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return int|string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param ConnectingObjectInterface $connectingObject
     */
    public function addConnectingObject(ConnectingObjectInterface $connectingObject)
    {
        $this->stateMachineBuilder->addTransition($connectingObject->getSource()->getId(), $connectingObject->getDestination()->getId(), $connectingObject->getDestination()->getId());
        $this->connectingObjectCollection->add($connectingObject);
    }

    /**
     * @param FlowObjectInterface $flowObject
     */
    public function addFlowObject(FlowObjectInterface $flowObject)
    {
        $this->stateMachineBuilder->addState($flowObject->getId());
        if ($flowObject instanceof StartEvent) {
            $this->stateMachineBuilder->addTransition(self::$STATE_START, $flowObject->getId(), $flowObject->getId());
        } elseif ($flowObject instanceof EndEvent) {
            $this->stateMachineBuilder->setEndState($flowObject->getId(), $flowObject->getId());
        }

        $this->flowObjectCollection->add($flowObject);
    }

    /**
     * @param int|string $id
     *
     * @return ConnectingObjectInterface|null
     */
    public function getConnectingObject($id)
    {
        return $this->connectingObjectCollection->get($id);
    }

    /**
     * @param TransitionalInterface $flowObject
     *
     * @return ConnectingObjectCollection
     */
    public function getConnectingObjectCollectionBySource(TransitionalInterface $flowObject)
    {
        return $this->connectingObjectCollection->filterBySource($flowObject);
    }

    /**
     * @param int|string $id
     *
     * @return FlowObjectInterface|null
     */
    public function getFlowObject($id)
    {
        return $this->flowObjectCollection->get($id);
    }

    /**
     * @param Role $role
     */
    public function addRole(Role $role)
    {
        $this->roleCollection->add($role);
    }

    /**
     * @param int|string $id
     *
     * @return bool
     */
    public function hasRole($id)
    {
        return $this->roleCollection->get($id) !== null;
    }

    /**
     * @param int|string $id
     *
     * @return Role
     */
    public function getRole($id)
    {
        return $this->roleCollection->get($id);
    }

    /**
     * {@inheritdoc}
     */
    public function isActive()
    {
        if ($this->stateMachine === null) {
            return false;
        }

        return $this->stateMachine->isActive();
    }

    /**
     * {@inheritdoc}
     */
    public function isEnded()
    {
        if ($this->stateMachine === null) {
            return false;
        }

        return $this->stateMachine->isEnded();
    }

    /**
     * @return FlowObjectInterface|null
     */
    public function getCurrentFlowObject()
    {
        if ($this->stateMachine === null) {
            return null;
        }

        $state = $this->stateMachine->getCurrentState();
        if ($state === null) {
            return null;
        }

        if ($state instanceof FinalState) {
            return $this->flowObjectCollection->get($this->stateMachine->getPreviousState()->getStateId());
        } else {
            return $this->flowObjectCollection->get($state->getStateId());
        }
    }

    /**
     * @return FlowObjectInterface|null
     */
    public function getPreviousFlowObject()
    {
        if ($this->stateMachine === null) {
            return null;
        }

        $state = $this->stateMachine->getPreviousState();
        if ($state === null) {
            return null;
        }

        $previousFlowObject = $this->flowObjectCollection->get($state->getStateId());
        if ($previousFlowObject instanceof EndEvent) {
            $transitionLogs = $this->stateMachine->getTransitionLog();

            return $this->flowObjectCollection->get($transitionLogs[count($transitionLogs) - 2]->getFromState()->getStateId());
        } else {
            return $previousFlowObject;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function start(StartEvent $event)
    {
        if ($this->stateMachine === null) {
            $this->stateMachine = $this->stateMachineBuilder->getStateMachine();
        }

        $this->startDate = new \DateTime();
        $this->stateMachine->start();
        $this->stateMachine->triggerEvent($event->getId());
        $this->selectSequenceFlow($event);
        $this->next();
    }

    public function startFlowTo($event)
    {
        if ($this->stateMachine === null) {
            $this->stateMachine = $this->stateMachineBuilder->getStateMachine();
        }

        if (is_string($event))
        {
            $options = $this->getNextOptions();
            foreach($options as $option)
            {
                if ($option->getName() == $event
                        || $option->getId() == $event)
                    $event = $option;
            }
        }

        if (!is_object($event))
            throw new \Exception("No such event: \"".$event."\".");

        $this->startDate = new \DateTime();
        $this->stateMachine->start();
        $this->stateMachine->triggerEvent($event->getId());
        $currentFlowObject = $this->getCurrentFlowObject();
        $connectingObject = $this->connectingObjectCollection->filterBySource($currentFlowObject);
        $selectedSequenceFlow = current(current($connectingObject));
        $this->stateMachine->triggerEvent($selectedSequenceFlow->getDestination()->getId());
        // $currentFlowObject = $this->getCurrentFlowObject();
        // $currentFlowObject->createWorkItem();
        // $this->allocateWorkItem($currentFlowObject, $participant);
        $this->intelligentNext();
        return ['options' => $this->getNextOptionsNames(), 'current' => $this->getCurrentFlowObject()->getName()];
    }

    private function initStateMachine()
    {
        $this->stateMachine = $this->stateMachineBuilder->getStateMachine();
    }

    public function getNextOptions()
    {
        if ($this->stateMachine == null) {
            $this->initStateMachine();
        }

        if ($this->getCurrentFlowObject() == null)
        {
            $starting = $this->stateMachine->getState(StateMachineInterface::STATE_INITIAL)->getStateId();
            $transition = current($this->stateMachine->getTransitionMap()[$starting]);
            $events = $transition->getToState()->getEvents();
            $next_states = [];
            foreach($events as $event)
                $next_states[] = $this->flowObjectCollection->get($event->getEventId());
            return $next_states;
        }
        else
        {
            $currentFlowObject = $this->getCurrentFlowObject();
            if ($currentFlowObject instanceOf \PHPMentors\Workflower\Workflow\Event\EndEvent)
                return [$currentFlowObject];

            $fos = $this->connectingObjectCollection->filterBySource($currentFlowObject);
            $next_sequences = [];
            foreach($fos as $next_sequence)
                $next_sequences[] = $next_sequence;
            return $next_sequences;
        }
    }

    public function getNextOptionsNames()
    {
        $names = [];
        foreach($this->getNextOptions() as $option)
        {
            $name = $option->getName();
            if ($name == null)
                $names[] = $option->getId();
            else
                $names[] = $name;
        }
        return $names;
    }

    /**
     * @param ActivityInterface    $activity
     * @param ParticipantInterface $participant
     */
    public function allocateWorkItem(ActivityInterface $activity, ParticipantInterface $participant)
    {
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);

        $activity->allocate($participant);
    }

    /**
     * @param ActivityInterface    $activity
     * @param ParticipantInterface $participant
     */
    public function startWorkItem(ActivityInterface $activity, ParticipantInterface $participant)
    {
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);

        $activity->start();
    }

    /**
     * @param ActivityInterface    $activity
     * @param ParticipantInterface $participant
     */
    public function completeWorkItem(ActivityInterface $activity, ParticipantInterface $participant)
    {
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);

        $activity->complete($participant);
        $this->selectSequenceFlow($activity);
        $this->next();
    }

    public function completeTask(ParticipantInterface $participant)
    {
        $activity = $this->getCurrentFlowObject();
        $this->assertParticipantHasRole($activity, $participant);
        $this->assertCurrentFlowObjectIsExpectedActivity($activity);
        $activity->complete($participant);
        $this->next();
    }

    /**
     * @param array $processData
     */
    public function setProcessData(array $processData)
    {
        $this->processData = $processData;
    }

    /**
     * @return array
     *
     * @since Method available since Release 1.2.0
     */
    public function getProcessData()
    {
        return $this->processData;
    }

    /**
     * @param ExpressionLanguage $expressionLanguage
     *
     * @since Method available since Release 1.1.0
     */
    public function setExpressionLanguage(ExpressionLanguage $expressionLanguage)
    {
        $this->expressionLanguage = $expressionLanguage;
    }

    /**
     * @param OperationRunnerInterface $operationRunner
     *
     * @since Method available since Release 1.2.0
     */
    public function setOperationRunner(OperationRunnerInterface $operationRunner)
    {
        $this->operationRunner = $operationRunner;
    }

    /**
     * @param EndEvent $event
     */
    private function end(EndEvent $event)
    {
        $this->stateMachine->triggerEvent($event->getId());
        $this->endDate = new \DateTime();
    }

    /**
     * @return \DateTime
     */
    public function getStartDate()
    {
        return $this->startDate;
    }

    /**
     * @return \DateTime
     */
    public function getEndDate()
    {
        return $this->endDate;
    }

    /**
     * @return ActivityLogCollection
     */
    public function getActivityLog()
    {
        $activityLogCollection = new ActivityLogCollection();
        foreach ($this->stateMachine->getTransitionLog() as $transitionLog) {
            $flowObject = $this->getFlowObject($transitionLog->getToState()->getStateId());
            if ($flowObject instanceof ActivityInterface) {
                $activityLogCollection->add(new ActivityLog($flowObject));
            }
        }

        return $activityLogCollection;
    }

    /**
     * @param string $stateMachineId
     *
     * @return StateMachineBuilder
     *
     * @since Property available since Release 2.0.0
     */
    private function createStateMachineBuilder($stateMachineId): StateMachineBuilder
    {
        $stateMachineBuilder = new StateMachineBuilder($stateMachineId);
        $stateMachineBuilder->addState(self::$STATE_START);
        $stateMachineBuilder->setStartState(self::$STATE_START);

        return $stateMachineBuilder;
    }

    /**
     * @param TransitionalInterface $currentFlowObject
     *
     * @throws SequenceFlowNotSelectedException
     */
    private function selectSequenceFlow(TransitionalInterface $currentFlowObject)
    {
        foreach ($this->connectingObjectCollection->filterBySource($currentFlowObject) as $connectingObject) { /* @var $connectingObject ConnectingObjectInterface */
            if ($connectingObject instanceof SequenceFlow) {
                if (!($currentFlowObject instanceof ConditionalInterface) || $connectingObject->getId() !== $currentFlowObject->getDefaultSequenceFlowId()) {
                    $condition = $connectingObject->getCondition();
                    if ($condition === null) {
                        $selectedSequenceFlow = $connectingObject;
                        break;
                    } else {
                        $expressionLanguage = $this->expressionLanguage ?: new ExpressionLanguage();
                        if ($expressionLanguage->evaluate($condition, $this->processData)) {
                            $selectedSequenceFlow = $connectingObject;
                            break;
                        }
                    }
                }
            }
        }

        if (!isset($selectedSequenceFlow)) {
            if (!($currentFlowObject instanceof ConditionalInterface) || $currentFlowObject->getDefaultSequenceFlowId() === null) {
                throw new SequenceFlowNotSelectedException(sprintf('No sequence flow can be selected on "%s".', $currentFlowObject->getId()));
            }

            $selectedSequenceFlow = $this->connectingObjectCollection->get($currentFlowObject->getDefaultSequenceFlowId());
        }

        $this->stateMachine->triggerEvent($selectedSequenceFlow->getDestination()->getId());

        if ($this->getCurrentFlowObject() instanceof GatewayInterface) {
            $gateway = $this->getCurrentFlowObject();
            $this->selectSequenceFlow(/* @var $gateway GatewayInterface */$gateway);
        }
    }

    /**
     * @param PHPMentors\Workflower\Workflow\Connection\SequenceFlow $nextSquence
     *
     * @throws SequenceFlowNotSelectedException
     */
    public function flowTo($nextSequence = null, $only_to_non_defaults = false)
    {
        if ($nextSequence === null)
        {
            $options = $this->getNextOptions();
            if (count($options) == 1)
            {
                $nextSequence = $options[0];
            }
        }

        if (is_string($nextSequence))
        {
            $options = $this->getNextOptions();
            foreach($options as $option)
            {
                if ($option->getName() == $nextSequence
                        || $option->getId() == $nextSequence
                        || $option->getDestination()->getName() == $nextSequence
                        || $option->getDestination()->getId() == $nextSequence)
                    $nextSequence = $option;
            }
        }

        if ($nextSequence instanceOf \PHPMentors\Workflower\Workflow\Event\EndEvent)
        {
            // just return null when ended
            return null;
        }

        if (!$nextSequence instanceof \PHPMentors\Workflower\Workflow\Connection\SequenceFlow) {
            throw new SequenceFlowNotSelectedException(sprintf('No sequence flow can be selected on "%s".', $nextSequence->getId()));
        }

        if ($only_to_non_defaults)
        {
            if ($nextSequence->getId() == $nextSequence->getSource()->getDefaultSequenceFlowId())
                return false;
        }

        $this->stateMachine->triggerEvent($nextSequence->getDestination()->getId());

        $currentFlowObject = $this->getCurrentFlowObject();
        if ($currentFlowObject instanceof \PHPMentors\Workflower\Workflow\Activity\Task || $currentFlowObject instanceof \PHPMentors\Workflower\Workflow\Gateway\ExclusiveGateway || $currentFlowObject instanceof EndEvent)
            $this->intelligentNext();

        return true;
    }

    public function jumpTo($name)
    {
        $this->stateMachine->jumpToState($name);
    }

    /**
     * @param ActivityInterface    $activity
     * @param ParticipantInterface $participant
     *
     * @throws AccessDeniedException
     */
    private function assertParticipantHasRole(ActivityInterface $activity, ParticipantInterface $participant)
    {
        if (!$participant->hasRole($activity->getRole()->getId())) {
            throw new AccessDeniedException(sprintf('The participant "%s" does not have the role "%s" that is required to operate the activity "%s".', $participant->getId(), $activity->getRole()->getId(), $activity->getId()));
        }
    }

    /**
     * @param ActivityInterface $activity
     *
     * @throws UnexpectedActivityException
     */
    private function assertCurrentFlowObjectIsExpectedActivity(ActivityInterface $activity)
    {
        if (!$activity->equals($this->getCurrentFlowObject())) {
            throw new UnexpectedActivityException(sprintf('The current flow object is not equal to the expected activity "%s".', $activity->getId()));
        }
    }

    /**
     * @since Method available since Release 1.2.0
     */
    private function next()
    {
        $currentFlowObject = $this->getCurrentFlowObject();
        if ($currentFlowObject instanceof ActivityInterface) {
            $currentFlowObject->createWorkItem();

            if ($currentFlowObject instanceof OperationalInterface) {
                $this->executeOperationalActivity($currentFlowObject);
            }
        } elseif ($currentFlowObject instanceof EndEvent) {
            $this->end($currentFlowObject);
        }
    }

    /**
     * @since Method available since Release 1.2.0
     */
    private function intelligentNext()
    {
        $currentFlowObject = $this->getCurrentFlowObject();
        if ($currentFlowObject instanceof ActivityInterface) {
            $currentFlowObject->createWorkItem();

            // we don't use operationRunner
            // if ($currentFlowObject instanceof OperationalInterface) {
            //     $this->executeOperationalActivity($currentFlowObject);
            // }
        } elseif ($currentFlowObject instanceof EndEvent) {
            $this->end($currentFlowObject);
        }
    }

    /**
     * @since Method available since Release 1.2.0
     *
     * @param ActivityInterface $operational
     */
    private function executeOperationalActivity(ActivityInterface $operational)
    {
        $participant = $this->operationRunner->provideParticipant(/* @var $operational OperationalInterface */ $operational, $this);
        $this->allocateWorkItem($operational, $participant);
        $this->startWorkItem($operational, $participant);
        $this->operationRunner->run(/* @var $operational OperationalInterface */ $operational, $this);
        $this->completeWorkItem($operational, $participant);
    }

    public function getRoleCollection()
    {
        return $this->roleCollection;
    }

    public function setRoleCollection($roleCollection)
    {
        $this->roleCollection = $roleCollection;
    }

    public function getFlowObjectCollection()
    {
        return $this->flowObjectCollection;
    }

    public function setFlowObjectCollection($flowObjectCollection)
    {
        $this->flowObjectCollection = $flowObjectCollection;
    }

    public function getconnectingObjectCollection()
    {
        return $this->connectingObjectCollection;
    }

    public function setconnectingObjectCollection($connectingObjectCollection)
    {
        $this->connectingObjectCollection = $connectingObjectCollection;
    }

    public function getStateMachine()
    {
        return $this->stateMachine;
    }

    public function setStateMachine($stateMachine)
    {
        $this->stateMachine = $stateMachine;
    }

    public function getStateMachineBuilder()
    {
        return $this->stateMachineBuilder;
    }

    public function setStateMachineBuilder($stateMachineBuilder)
    {
        $this->stateMachineBuilder = $stateMachineBuilder;
    }

    public function recreateStateMachineBuilder($id)
    {
        $this->stateMachineBuilder = $this->createStateMachineBuilder($this->id);
    }

    public function setId($id)
    {
        $this->id = $id;
    }
}
