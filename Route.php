<?php
namespace Asgard\Http;

/**
 * 
 */
class Route {
	/**
	 * Route.
	 * @var string
	 */
	protected $route;
	/**
	 * Controller class.
	 * @var string
	 */
	protected $controller;
	/**
	 * Action name.
	 * @var string
	 */
	protected $action;
	/**
	 * Route parameters.
	 * @var array
	 */
	protected $parameters;

	/**
	 * Constructor.
	 * @param string $route
	 * @param string $controller
	 * @param string $action
	 * @param array  $parameters
	 */
	public function __construct($route, $controller, $action, $parameters=[]) {
		$this->route = $route;
		$this->controller = $controller;
		$this->action = $action;
		$this->parameters = $parameters;
	}

	/**
	 * Get an parameter.
	 * @param  string $name
	 * @return mixed
	 */
	public function get($name) {
		if(!isset($this->parameters[$name]))
			return;
		return $this->parameters[$name];
	}

	/**
	 * Set a parameter.
	 * @param string $name
	 * @param mixed $value
	 */
	public function set($name, $value) {
		$this->parameters[$name] = $value;
	}

	/**
	 * Get the route.
	 * @return string
	 */
	public function getRoute() {
		return $this->route;
	}

	/**
	 * Set the route.
	 * @param string $route
	 */
	public function setRoute($route) {
		$this->route = $route;
	}

	/**
	 * Get the controller class.
	 * @return string
	 */
	public function getController() {
		return $this->controller;
	}

	/**
	 * Get the action name.
	 * @return string
	 */
	public function getAction() {
		return $this->action;
	}
}