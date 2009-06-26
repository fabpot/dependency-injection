<?php

/*
 * This file is part of the symfony framework.
 *
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * sfServiceContainerBuilder is a DI container that provides an interface to build the services.
 *
 * @package    symfony
 * @subpackage service
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfServiceContainerBuilder.php 269 2009-03-26 20:39:16Z fabien $
 */
class sfServiceContainerBuilder extends sfServiceContainer
{
  protected
    $definitions = array(),
    $loading = array();

  /**
   * Returns true if the given service is defined.
   *
   * @param  string  $id      The service identifier
   *
   * @return Boolean true if the service is defined, false otherwise
   */
  public function hasService($name)
  {
    return isset($this->definitions[$name]) || parent::hasService($name);
  }

  /**
   * Gets a service.
   *
   * @param  string $id The service identifier
   *
   * @return object The associated service
   *
   * @throw InvalidArgumentException if the service is not defined
   * @throw LogicException if the service has a circular reference to itself
   */
  public function getService($id)
  {
    try
    {
      return parent::getService($id);
    }
    catch (InvalidArgumentException $e)
    {
      if (isset($this->loading[$id]))
      {
        throw new LogicException(sprintf('The service "%s" has a circular reference to itself.', $id));
      }

      $definition = $this->getServiceDefinition($id);

      $this->loading[$id] = true;

      if ($definition->isShared())
      {
        $service = $this->services[$id] = $this->createService($definition);
      }
      else
      {
        $service = $this->createService($definition);
      }

      unset($this->loading[$id]);

      return $service;
    }
  }

  /**
   * Gets all services.
   *
   * Calling this method should be avoided as it creates all the services
   * defined for this service container.
   *
   * It is mostly useful for testing purpose.
   *
   * @return array An array of services
   */
  public function getServices()
  {
    $allServices = parent::getServices();

    $services = array();
    foreach ($this->getServiceDefinitions() as $id => $definition)
    {
      $services[$id] = $this->getService($id);
    }

    return array_merge($services, $allServices);
  }

  /**
   * Registers a service definition.
   *
   * This methods allows for simple registration of service definition
   * with a fluid interface.
   *
   * @param  string $id    The service identifier
   * @param  string $class The service class
   *
   * @return sfServiceDefinition A sfServiceDefinition instance
   */
  public function register($id, $class)
  {
    return $this->setServiceDefinition($id, new sfServiceDefinition($class));
  }

  /**
   * Sets the service definitions.
   *
   * @param array $definitions An array of service definitions
   */
  public function setServiceDefinitions(array $definitions)
  {
    foreach ($definitions as $id => $definition)
    {
      $this->setServiceDefinition($id, $definition);
    }
  }

  /**
   * Gets all service definitions.
   *
   * @return array An array of sfServiceDefinition instances
   */
  public function getServiceDefinitions()
  {
    return $this->definitions;
  }

  /**
   * Sets a service definition.
   *
   * @param  string              $id         The service identifier
   * @param  sfServiceDefinition $definition A sfServiceDefinition instance
   */
  public function setServiceDefinition($id, sfServiceDefinition $definition)
  {
    return $this->definitions[$id] = $definition;
  }

  /**
   * Returns true if a service definition exists under the given identifier.
   *
   * @param  string  $id The service identifier
   *
   * @return Boolean true if the service definition exists, false otherwise
   */
  public function hasServiceDefinition($id)
  {
    return array_key_exists($id, $this->definitions);
  }

  /**
   * Gets a service definition.
   *
   * @param  string  $id The service identifier
   *
   * @return sfServiceDefinition A sfServiceDefinition instance
   *
   * @throw InvalidArgumentException if the service definition does not exist
   */
  public function getServiceDefinition($id)
  {
    if (!$this->hasServiceDefinition($id))
    {
      throw new InvalidArgumentException(sprintf('The service definition "%s" does not exist.', $id));
    }

    return $this->definitions[$id];
  }

  /**
   * Creates a service for a service definition.
   *
   * @param  sfServiceDefinition $definition A service definition instance
   *
   * @return object              The service described by the service definition
   */
  protected function createService(sfServiceDefinition $definition)
  {
    if (!is_null($definition->getFile()))
    {
      require_once $this->resolveValue($definition->getFile());
    }

    $r = new ReflectionClass($this->resolveValue($definition->getClass()));

    $arguments = $this->resolveServices($this->resolveValue($definition->getArguments()));

    if (!is_null($definition->getConstructor()))
    {
      $service = call_user_func_array(array($definition->getClass(), $definition->getConstructor()), $arguments);
    }
    else
    {
      $service = is_null($r->getConstructor()) ? $r->newInstance() : $r->newInstanceArgs($arguments);
    }

    foreach ($definition->getMethodCalls() as $call)
    {
      call_user_func_array(array($service, $call[0]), $this->resolveServices($this->resolveValue($call[1])));
    }

    if ($callable = $definition->getConfigurator())
    {
      if (is_array($callable) && is_object($callable[0]) && $callable[0] instanceof sfServiceReference)
      {
        $callable[0] = $this->getService((string) $callable[0]);
      }
      elseif (is_array($callable))
      {
        $callable[0] = $this->resolveValue($callable[0]);
      }

      if (!is_callable($callable))
      {
        throw new InvalidArgumentException(sprintf('The configure callable for class "%s" is not a callable.', get_class($service)));
      }

      call_user_func($callable, $service);
    }

    return $service;
  }

  /**
   * Replaces parameter placeholders (%name%) by their values.
   *
   * @param  mixed $value A value
   *
   * @return mixed The same value with all placeholders replaced by their values
   *
   * @throw RuntimeException if a placeholder references a parameter that does not exist
   */
  public function resolveValue($value)
  {
    if (is_array($value))
    {
      $args = array();
      foreach ($value as $k => $v)
      {
        $args[$this->resolveValue($k)] = $this->resolveValue($v);
      }

      $value = $args;
    }
    else if (is_string($value))
    {
      if (preg_match('/^%([^%]+)%$/', $value, $match))
      {
        // we do this to deal with non string values (boolean, integer, ...)
        // the preg_replace_callback converts them to strings
        if (!$this->hasParameter($name = strtolower($match[1])))
        {
          throw new RuntimeException(sprintf('The parameter "%s" must be defined.', $name));
        }

        $value = $this->getParameter($name);
      }
      else
      {
        $value = preg_replace_callback('/(%{1,2})([^%]+)\1/', array($this, 'replaceParameter'), $value);
      }
    }

    return $value;
  }

  /**
   * Replaces service references by the real service instance.
   *
   * @param  mixed $value A value
   *
   * @return mixed The same value with all service references replaced by the real service instances
   */
  public function resolveServices($value)
  {
    if (is_array($value))
    {
      $value = array_map(array($this, 'resolveServices'), $value);
    }
    else if (is_object($value) && $value instanceof sfServiceReference)
    {
      $value = $this->getService((string) $value);
    }

    return $value;
  }

  protected function replaceParameter($match)
  {
    if ('%%' == $match[1])
    {
      // % escaping
      return '%'.$match[2].'%';
    }

    if (!$this->hasParameter($name = strtolower($match[2])))
    {
      throw new RuntimeException(sprintf('The parameter "%s" must be defined.', $name));
    }

    return $this->getParameter($name);
  }
}
