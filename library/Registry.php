<?php

namespace Guide42\Suda;

class Registry implements RegistryInterface
{
    public $settings = array();

    private $services = array();
    private $factories = array();
    private $definitions = array();

    /* This contains the instances of \ReflectionClass that will
     * be used to create new instances of services */
    private $reflcache = array();

    /* Used to detect cyclic dependency, will contain the name of
     * the class being created in the moment as key and a simple
     * true as value */
    private $loading = array();

    /**
     * @var \Guide42\Suda\RegistryInterface
     */
    private $delegate;

    public function __construct($delegate=null) {
        if ($delegate === null) {
            $delegate = $this;
        }

        $this->delegate = $delegate;
    }

    public function setDelegateLookupContainer(RegistryInterface $delegate) {
        $this->delegate = $delegate;
    }

    public function register($service, $name='') {
        $interfaces = class_implements($service);

        if (empty($interfaces)) {
            throw new Exception\RegistryException(
                'Service must implement at least one interface',
                Exception\RegistryException::MUST_IMPLEMENT_INTERFACE
            );
        }

        foreach ($interfaces as $interface) {
            $this->services[$interface][$name] = $service;
        }
    }

    public function registerFactory($interfaces, \Closure $factory, $name='') {
        $refl = new \ReflectionFunction($factory);
        $reflParams = $refl->getParameters();

        $params = array();
        foreach ($reflParams as $pos => $relfParam) {
            if ($relfParam->isDefaultValueAvailable()) {
                $params[$pos] = $relfParam->getDefaultValue();
            } elseif (($classHint = $relfParam->getClass()) !== null) {
                $params[$pos] = $classHint->getName();
            }
        }

        foreach ((array) $interfaces as $interface) {
            $this->factories[$interfaces][$name] = array($factory, $params);
        }
    }

    public function registerDefinition($class, $name='', array $args=array()) {
        $interfaces = class_implements($class);

        if (empty($interfaces)) {
            throw new Exception\RegistryException(
                'Factory must implement at least one interface',
                Exception\RegistryException::MUST_IMPLEMENT_INTERFACE
            );
        }

        foreach ($interfaces as $interface) {
            $this->definitions[$interface][$name] = array($class, $args);
        }
    }

    public function get($interface, $name='', array $context=array()) {
        if (isset($this->services[$interface][$name])) {
            return $this->services[$interface][$name];
        }

        if (isset($this->factories[$interface][$name])) {
            list($factory, $arguments) = $this->factories[$interface][$name];

            $parameters = $this->buildContext($arguments, $context);
            $service = call_user_func_array($factory, $parameters);

            return $this->services[$interface][$name] = $service;
        }

        if (isset($this->definitions[$interface][$name])) {
            list($class, $arguments) = $this->definitions[$interface][$name];

            if (isset($this->loading[$class])) {
                throw new Exception\RegistryException(
                    "Cyclic dependency detected for $class",
                    Exception\RegistryException::CYCLIC_DEPENDENCY_DETECTED
                );
            }

            $this->loading[$class] = true;

            if (isset($this->reflcache[$class])) {
                $refl = $this->reflcache[$class];
            } else {
                $refl = $this->reflcache[$class]
                      = new \ReflectionClass($class);
            }

            $parameters = $this->buildContext($arguments, $context);
            $service = $refl->newInstanceArgs($parameters);

            unset($this->loading[$class]);

            return $this->services[$interface][$name] = $service;
        }

        throw new Exception\NotFoundException(
            "Service \"$name\" for $interface not found"
        );
    }

    public function getAll($interface) {
        if (isset($this->services[$interface])) {
            return $this->services[$interface];
        }

        throw new Exception\NotFoundException(
            "Services for $interface not found"
        );
    }

    public function has($interface, $name='') {
        return isset($this->services[$interface][$name]) ||
               isset($this->definitions[$interface][$name]);
    }

    private function buildContext(array $arguments, array $parameters) {
        $context = array_replace($arguments, $parameters);

        foreach ($context as $index => $value) {
            if (is_string($value) && $this->has($value)) {
                $context[$index] = $this->delegate->get($value);
            } elseif (is_array($value) && count($value) === 2 &&
                $this->has($value[0], $value[1])
            ) {
                $context[$index] = $this->delegate->get($value[0], $value[1]);
            }
        }

        return $context;
    }
}