<?php
namespace Asgard\Http\Browser;

class Browser {
	protected $cookies;
	protected $session;
	protected $last;
	protected $app;
	protected $catchException = false;

	public function __construct(\Asgard\Container\Container $app) {
		$this->app = $app;
		$this->cookies = new CookieManager;
		$this->session = new SessionManager;
	}

	public function getCookies() {
		return $this->cookies;
	}

	public function getSession() {
		return $this->session;
	}

	public function getLast() {
		return $this->last;
	}

	public function get($url='', $body='', array $headers=[]) {
		return $this->req($url, 'GET', [], [], $body, $headers);
	}

	public function post($url='', array $post=[], array $files=[], $body='', array $headers=[]) {
		return $this->req($url, 'POST', $post, $files, $body, $headers);
	}

	public function put($url='', array $post=[], array $files=[], $body='', array $headers=[]) {
		return $this->req($url, 'PUT', $post, $files, $body, $headers);
	}

	public function delete($url='', $body='', array $headers=[]) {
		return $this->req($url, 'DELETE', [], [], $body, $headers);
	}

	public function req(
			$url='',
			$method='GET',
			array $post=[],
			array $file=[],
			$body='',
			array $headers=[],
			array $server=[]
		) {
		if(defined('_TESTING_'))
			file_put_contents(_TESTING_, $url."\n", FILE_APPEND);

		#build request
		$get = [];
		$infos = parse_url($url);
		if(isset($infos['query'])) {
			parse_str($infos['query'], $get);
			$url = preg_replace('/(\?.*)$/', '', $url);
		}
		$request = new \Asgard\Http\Request;
		$request->setMethod($method);
		$request->get->setAll($get);
		$request->post->setAll($post);
		$request->file->setAll($file);
		$request->header->setAll($headers);
		$request->server->setAll($server);
		$request->cookie = $this->cookies;
		$request->session = $this->session;
		if(count($post))
			$request->body = http_build_query($post);
		else
			$request->body = $body;

		$request->url->setURL($url);
		$request->url->setHost('localhost');
		$request->url->setRoot('');

		$httpKernel = $this->app['httpKernel'];
		$res = $httpKernel->process($request, $this->catchException);

		$this->last = $res;
		$this->cookies = $request->cookie;
		$this->session = $request->session;

		return $res;
	}

	public function catchException($catchException) {
		$this->catchException = $catchException;
	}

	public function submit($item=0, $to=null, array $override=[]) {
		if($this->last === null)
			throw new \Exception('No page to submit from.');
		libxml_use_internal_errors(true);
		$orig = new \DOMDocument();
		$orig->loadHTML($this->last->content);
		$node = $orig->getElementsByTagName('form')->item($item);

		$parser = new FormParser;
		$parser->parse($node);
		$parser->clickOn('send');
		$res = $parser->values();
		$this->merge($res, $override);

		return $this->post($to, $res);
	}

	protected function merge(array &$arr1, array &$arr2) {
		foreach($arr2 as $k=>$v) {
			if(is_array($arr1[$k]) && is_array($arr2[$k]))
				$this->merge($arr1[$k], $arr2[$k]);
			elseif($arr1[$k] !== $arr2[$k])
				$arr1[$k] = $arr2[$k];
		}
	}
}