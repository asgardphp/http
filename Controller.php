<?php
namespace Asgard\Http;

class Controller extends \Asgard\Hook\Hookable {
	protected $_view;
	public $request;
	public $response;
	protected $app;

	public static function fetchRoutes() {
		$routes = array();
		$class = get_called_class();
		$reflection = new \Addendum\ReflectionAnnotatedClass($class);
		
		if($reflection->getAnnotation('Prefix'))
			$prefix = Resolver::formatRoute($reflection->getAnnotation('Prefix')->value);
		else
			$prefix = '';
		
		$methods = get_class_methods($class);
		foreach($methods as $method) {
			if(!preg_match('/Action$/i', $method))
				continue;
			$method_reflection = new \Addendum\ReflectionAnnotatedMethod($class, $method);
			if($method_reflection->getAllAnnotations('Route')) {
				foreach($method_reflection->getAllAnnotations('Route') as $annotation) {
					$route = Resolver::formatRoute($prefix.'/'.$annotation->value);

					$routes[] = new ControllerRoute(
						$route,
						$class,
						Resolver::formatActionName($method),
						array(
							'host' => $method_reflection->getAnnotation('Route')->host,
							'requirements' => $method_reflection->getAnnotation('Route')->requirements,
							'method' => $method_reflection->getAnnotation('Route')->method,
							'name'	=>	isset($method_reflection->getAnnotation('Route')->name) ? $method_reflection->getAnnotation('Route')->name:null
						)
					);
				}
			}
		}

		return $routes;
	}

	public function setApp($app) {
		$this->app = $app;
		$this->hook = $app['hook'];
	}

	public function getapp() {
		return $this->app;
	}
	
	public function getRouteFor($what) {
		foreach(App::get('resolver')->getRoutes() as $route) {
			if($route instanceof ControllerRoute && $route->getController() == $what[0] && $route->getAction() == $what[1])
				return $route->getRoute();
		}
	}

	public function notfound($msg=null) {
		throw new Exception\NotFoundException($msg);
	}

	public function addFilter($filter) {
		$filter->setController($this);
		if(method_exists($filter, 'before')) 
			$this->hook('before', array($filter, 'before'), $filter->getBeforePriority());
		if(method_exists($filter, 'after'))
			$this->hook('after', array($filter, 'after'), $filter->getAfterPriority());
	}

	public static function route_for($action) {
		$routes = static::routes_for($action);
		if(!isset($routes[0]))
			return;
		return $routes[0];
	}

	public static function routes_for($action) {
		$routes = array();
		$reflection = new \Addendum\ReflectionAnnotatedClass(get_called_class());
		
		if($reflection->getAnnotation('Prefix'))
			$prefix = Resolver::formatRoute($reflection->getAnnotation('Prefix')->value);
		else
			$prefix = '';

		$method = $action.'Action';
		$method_reflection = new \Addendum\ReflectionAnnotatedMethod(get_called_class(), $method);
		if($method_reflection->getAllAnnotations('Route')) {
			foreach($method_reflection->getAllAnnotations('Route') as $annotation)
				$routes[] = Resolver::formatRoute($prefix.'/'.$annotation->value);
		}
		return $routes;
	}
	
	public function url_for($action, $params=array(), $relative=false) {
		return $this->app['resolver']->url_for(array(get_called_class(), $action), $params, $relative);
	}

	public static function run($controllerClassName, $actionShortname, \Asgard\Core\App $app, $request=null, $response=null) {
		if($request === null)
			$request = new Request;
		if($response === null)
			$response = new Response;

		$actionName = $actionShortname.'Action';
		$controller = new $controllerClassName;
		$controller->setApp($app);

		$request->route = array('controller'=>$controllerClassName, 'action'=>$actionShortname);
		$controller->request = $request;
		$controller->response = $response;

		$app['hook']->trigger('controller_configure', array($controller));

		if(method_exists($controller, 'before')) {
			$controller->hook('before', function($chain, $controller, $request) {
				return call_user_func_array(array($controller, 'before'), array($request));
			});
		}
		if(method_exists($controller, 'after')) {
			$controller->hook('after', function($chain, $controller, &$result) {
				return call_user_func_array(array($controller, 'after'), array(&$result));
			});
		}

		if(!$result = $controller->trigger('before', array($controller, $request))) {
			$result = $controller->doRun($actionName, array($request));
			$controller->trigger('after', array($controller, &$result));
		}

		if($result !== null) {
			if(is_string($result))
				return $controller->response->setContent($result);
			elseif($result instanceof Response)
				return $result;
			else
				throw new \Exception('Controller response is invalid.');
		}
		else
			return $controller->response;
	}

	public static function widget($class, $method, array $params=array()) {
		$controller = new $class;
		return $controller->doRun($method, $params);
	}

	protected function doRun($method, array $params=array()) {
		$this->_view = null;

		ob_start();
		$result = call_user_func_array(array($this, $method), $params);
		$controllerBuffer =  ob_get_clean();

		if($result !== null)
			return $result;
		if($controllerBuffer)
			return $controllerBuffer;
		elseif($this->_view !== false) {
			if($this->_view instanceof View)
				return $this->_view->render();
			else {
				$method = preg_replace('/Action$/', '', $method);
				if($this->_view === null && !$this->setRelativeView($method.'.php'))
					return null;
				return $this->renderView($this->_view, (array)$this);
			}
		}
		return null;
	}

	public function getFlash() {
		return new \Asgard\Utils\Flash($this->request);
	}
	
	protected function renderView($_view, array $_args=array()) {
		foreach($_args as $_key=>$_value)
			$$_key = $_value;

		ob_start();
		include($_view);
		return ob_get_clean();
	}

	public function noView() {
		$this->_view = false;
	}
	
	public function setView($view) {
		$this->_view = $view;
	}
	
	public function setRelativeView($view) {
		$reflection = new \ReflectionObject($this);
		$dir = dirname($reflection->getFileName());
		$this->setView($dir.'/../views/'.strtolower(preg_replace('/Controller$/i', '', \Asgard\Utils\NamespaceUtils::basename(get_class($this)))).'/'.$view);
		return file_exists($dir.'/../views/'.strtolower(preg_replace('/Controller$/i', '', \Asgard\Utils\NamespaceUtils::basename(get_class($this)))).'/'.$view);
	}

	public function back() {
		return $this->response->redirect($this->request->server->get('HTTP_REFERER'));
	}
}