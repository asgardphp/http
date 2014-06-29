<?php
namespace Asgard\Http;

class HttpKernel {
	protected $app;
	protected $loaded = false;
	protected $requests = [];
	protected $start;
	protected $end;
	protected $templatePathSolvers = [];

	protected $filters = [];
	protected $beforeFilters = [];
	protected $afterFilters = [];

	public function __construct($app) {
		$this->app = $app;
	}

	public function start($start) {
		$this->start = $start;
	}

	public function end($end) {
		$this->end = $end;
	}

	public function run() {
		$request = \Asgard\Http\Request::singleton();
		$request->isInitial = true;

		$response = $this->process($request);

		$this->app['hooks']->trigger('Asgard.Http.Output', [$response, $request]);
		return $response;
	}

	private function setRequest($request) {
		$this->app['request'] = $request;
		\Asgard\Http\Request::setInstance($request);
	}

	public function process(Request $request, $catch=true) {
		$this->setRequest($request);
		$this->requests[] = $request;

		if(!$catch) {
			$response = $this->processRaw($request);
			if(!$response instanceof Response)
				$response = (new Response())->setContent($response);
		}
		else {
			try {
				$response = $this->processRaw($request);
				if(!$response instanceof Response)
					$response = (new Response())->setContent($response);
			} catch(\Exception $e) {
				if($e instanceof ControllerException) {
					$response = $e->getResponse();
					$severity = $e->getSeverity();
					$trace = $this->app['errorHandler']->getBacktraceFromException($e);
					$this->app['errorHandler']->log($severity, $e->getMessage(), $e->getFile(), $e->getLine(), $trace);
				}
				else {
					$response = null;
					$this->app['errorHandler']->logException($e);
				}

				$this->app['hooks']->trigger('Asgard.Http.Exception.'.get_class($e), [$e, &$response, $request]);
				if($response === null)
					$response = $this->getExceptionResponse($e);
			}
		}

		try {
			if($this->end !== null)
				include $this->end;
			$this->app['hooks']->trigger('Asgard.Http.End', [$response]);
		} catch(\Exception $e) {
			$this->app['errorHandler']->logException($e);
		}

		array_pop($this->requests);
		if(isset($this->requests[count($this->requests)-1]))
			$this->setRequest($this->requests[count($this->requests)-1]);

		return $response;
	}

	public function getLastRequest() {
		if(!isset($this->requests[count($this->requests)-1]))
			return;
		return $this->requests[count($this->requests)-1];
	}

	protected function processRaw(Request $request, $catch=true) {
		$resolver = $this->app['resolver'];
		$resolver->sortRoutes();

		if($response = $this->app['hooks']->trigger('Asgard.Http.Start', [$request]))
			return $response;
		if($this->start !== null) {
			$app = $this->app;
			if(($response = include $this->start) !== 1)
				return $response;
		}

		$route = $resolver->getRoute($request);
		if($route === null)
			throw new Exceptions\NotFoundException;

		$controllerClass = $route->getController();
		$action = $route->getAction();

		return $this->runController($controllerClass, $action, $request, $route);
	}

	public function runController($controllerClass, $action, $request, $route=null) {
		$controller = new $controllerClass();
		$controller->setApp($this->app);

		$this->addFilters($controllerClass, $controller, $request, $route);
		
		if($this->app instanceof \Asgard\Container\Container && $this->app->has('templateEngine'))
			$controller->setTemplateEngine($this->app->make('templateEngine', [$controller]));
		else {
			foreach($this->templatePathSolvers as $cb)
				$controller->addTemplatePathSolver($cb);
		}

		return $controller->run($action, $request);
	}

	public function addTemplatePathSolver($cb) {
		$this->templatePathSolvers[] = $cb;
	}

	protected function getExceptionResponse($e) {
		while(ob_get_length())
			ob_end_clean();

		$this->app['errorHandler']->exceptionHandler($e, false);

		$trace = $this->app['errorHandler']->getBacktraceFromException($e);
		
		if($e instanceof \Asgard\Debug\PSRException)
			$msg = $e->getMessage();
		elseif($e instanceof \ErrorException)
			$msg = 'PHP ('.$this->app['errorHandler']->getPHPError($e->getCode()).'): '.$e->getMessage();
		else
			$msg = get_class($e).': '.$e->getMessage();

		$result = '<b>Message</b><br>'."\n"
			. $msg."<hr>\n"
			. \Asgard\Debug\Debug::getReport($trace);
	
		$response = new \Asgard\Http\Response(500);
		if($this->app['config']['debug'])
			return $response->setHeader('Content-Type', 'text/html')->setContent($result);
		else
			return $response->setHeader('Content-Type', 'text/html')->setContent($this->app['translator']->trans('<h1>Error</h1>Oops, something went wrong.'));
	}

	public function filterAll($filter, $args=[]) {
		$this->filters[] = ['filter'=>$filter, 'args'=>$args];
		return $this;
	}

	public function filter($criteria, $filter, $args=[]) {
		$this->filters[] = ['criteria'=>$criteria, 'filter'=>$filter, 'args'=>$args];
		return $this;
	}

	public function filterBeforeAll($filter) {
		$this->beforeFilters[] = ['filter'=>$filter];
		return $this;
	}

	public function filterBefore($criteria, $filter) {
		$this->beforeFilters[] = ['criteria'=>$criteria, 'filter'=>$filter];
		return $this;
	}

	public function filterAfterAll($filter) {
		$this->afterFilters[] = ['filter'=>$filter];
		return $this;
	}

	public function filterAfter($criteria, $filter) {
		$this->afterFilters[] = ['criteria'=>$criteria, 'filter'=>$filter];
		return $this;
	}

	protected function addFilters($controllerClass, $controller, $request, $route=null) {
		foreach($this->filters as $_filter) {
			$args = $_filter['args'];
			$filter = $_filter['filter'];

			if(isset($criteria)) {
				$criteria = $_filter['criteria'];
				if(isset($criteria['actions'])) {
					if($criteria['actions'] && strpos($controllerClass.':'.$action, $criteria['actions']) !== 0)
						continue;
				}
				if($route !== null && isset($criteria['route'])) {
					foreach($criteria['methods'] as $method) {
						if($criteria['route'] && strpos($route->getRoute(), $criteria['route']) !== 0 || strtoupper($method) !== $request->method())
							continue;
					}
				}
			}

			$reflector = new \ReflectionClass($filter);
			$controller->addFilter($reflector->newInstanceArgs($args));
		}

		foreach($this->beforeFilters as $_filter) {
			$filter = $_filter['filter'];

			if(isset($criteria)) {
				$criteria = $_filter['criteria'];
				if(isset($criteria['actions'])) {
					if($criteria['actions'] && strpos($controllerClass.':'.$action, $criteria['actions']) !== 0)
						continue;
				}
				if($route !== null && isset($criteria['route'])) {
					foreach($criteria['methods'] as $method) {
						if($criteria['route'] && strpos($route->getRoute(), $criteria['route']) !== 0 || strtoupper($method) !== $request->method())
							continue;
					}
				}
			}

			$controller->addBeforeFilter($filter);
		}

		foreach($this->afterFilters as $_filter) {
			$filter = $_filter['filter'];

			if(isset($criteria)) {
				$criteria = $_filter['criteria'];
				if(isset($criteria['actions'])) {
					if($criteria['actions'] && strpos($controllerClass.':'.$action, $criteria['actions']) !== 0)
						continue;
				}
				if($route !== null && isset($criteria['route'])) {
					foreach($criteria['methods'] as $method) {
						if($criteria['route'] && strpos($route->getRoute(), $criteria['route']) !== 0 || strtoupper($method) !== $request->method())
							continue;
					}
				}
			}

			$controller->addAfterFilter($filter);
		}
	}
}
