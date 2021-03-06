<?php
namespace Cubex\Kernel;

use Cubex\CubexAwareTrait;
use Cubex\CubexException;
use Cubex\ICubexAware;
use Cubex\Routing\IRoutable;
use Cubex\Routing\IRoute;
use Cubex\Routing\IRouter;
use Cubex\Routing\Route;
use Cubex\Routing\RouteNotFoundException;
use Illuminate\Support\Contracts\RenderableInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Cubex\Http\Response as CubexResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

abstract class CubexKernel
  implements HttpKernelInterface, ICubexAware, IRoutable
{
  use CubexAwareTrait;

  /**
   * @var int Level to which routing has automatically processed
   */
  protected $_routeLevel = 0;

  /**
   * @var array params sent through by the constructor
   */
  protected $_processParams;

  /**
   * Initialise component
   */
  public function init()
  {
  }

  /**
   * Shutdown component
   */
  public function shutdown()
  {
  }

  /**
   * Get available routes for this object
   *
   * @return array|null
   */
  public function getRoutes()
  {
    return null;
  }

  /**
   * If no route can be processed, run this action
   *
   * @return null
   */
  public function defaultAction()
  {
    return null;
  }

  /**
   * Handles a Request to convert it to a Response.
   *
   * When $catch is true, the implementation must catch all exceptions
   * and do its best to convert them to a Response instance.
   *
   * @param Request $request  A Request instance
   * @param integer $type     The type of the request
   *                          (one of HttpKernelInterface::MASTER_REQUEST
   *                          or HttpKernelInterface::SUB_REQUEST)
   * @param Boolean $catch    Whether to catch exceptions or not
   *
   * @return Response A Response instance
   *
   * @throws \Exception When an Exception occurs during processing
   *
   * @api
   */
  public function handle(
    Request $request, $type = self::MASTER_REQUEST, $catch = true
  )
  {
    //Imitialise the kernel
    $this->init();

    try
    {
      //Get the default router
      $router = $this->getCubex()->makeWithCubex('\Cubex\Routing\IRouter');
      if(!($router instanceof IRouter))
      {
        throw new \RuntimeException("No IRouter located");
      }
      //Tell the router who its working for
      $router->setSubject($this);
      $response = null;
      try
      {
        //Process the route to get the response
        $response = $this->executeRoute(
          $router->process($request->getPathInfo()),
          $request,
          $type,
          $catch
        );
      }
      catch(RouteNotFoundException $e)
      {
        $response = $this->autoRoute($request);
      }

      if(!($response instanceof Response))
      {
        $response = $this->executeRoute(
          Route::create('defaultAction'),
          $request,
          $type,
          $catch
        );
      }

      if(!($response instanceof Response))
      {
        throw CubexException::debugException(
          "The processed route did not yield a valid response",
          500,
          $response
        );
      }
    }
    catch(CubexException $e)
    {
      $this->shutdown();
      return $this->getCubex()->make('404');
    }
    catch(\Exception $e)
    {
      if($catch)
      {
        //shutdown the kernel
        $this->shutdown();
        return $this->getCubex()->exceptionResponse($e);
      }
      else
      {
        //shutdown the kernel
        $this->shutdown();

        throw $e;
      }
    }

    //shutdown the kernel
    $this->shutdown();

    return $response;
  }

  public function executeRoute(
    IRoute $route, Request $request, $type = self::MASTER_REQUEST,
    $catch = true
  )
  {
    $value  = $route->getValue();
    $params = $route->getRouteData();

    //If the action has returned a valid response, lets send that back
    if($value instanceof Response)
    {
      return $value;
    }

    if(is_scalar($value))
    {
      //If is fully qualified class, create class
      $match = '/^(\\\?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)+$/';
      if(stripos($value, '\\') !== false && preg_match($match, $value))
      {
        $class   = $value;
        $nsClass = build_path_win(get_namespace($this), $value);

        try
        {
          if(class_exists($nsClass))
          {
            $value = $this->getCubex()->make($nsClass);
          }
          else
          {
            $value = $this->getCubex()->make($class);
          }
        }
        catch(\Exception $e)
        {
          throw new \RuntimeException(
            "Your route provides an invalid class '$class'"
          );
        }
      }
      else
      {
        //Attempt to see if the route is a callable
        $call = $this->attemptCallable($value);
        if($call !== null)
        {
          return $call();
        }

        //Attempt to see if the route is a redirect
        $url = $this->attemptUrl($value, $request);
        if($url !== null)
        {
          //Redirect to url
          return new RedirectResponse($url['url'], $url['code']);
        }

        //look for method names
        $method = $this->attemptMethod($value, $request);
        if($method !== null)
        {
          if($params === null && $method === 'defaultAction')
          {
            $params = $this->_processParams;
          }
          /*
           * TODO: Find a use case for this, or if its fixed upstream
          else if($params === null && count($this->_processParams) > 0)
          {
            $params = array_slice($this->_processParams, 1);
          }*/

          $value = $this->_getMethodResult($method, $params);
        }
      }
    }
    return $this->_processResponse($value, $request, $type, $catch, $params);
  }

  protected function _processResponse(
    $value, Request $request, $type = self::MASTER_REQUEST, $catch = true,
    $params = []
  )
  {
    if(!is_array($params))
    {
      $params = [];
    }

    if($value instanceof ICubexAware)
    {
      $value->setCubex($this->getCubex());
    }

    if($value instanceof Response)
    {
      return $value;
    }

    if($value instanceof CubexKernel)
    {
      $value->_processParams = $params;
    }

    //Support for nested kernels, e.g. project > application > controller
    if($value instanceof HttpKernelInterface)
    {
      return $value->handle($request, $type, $catch);
    }

    if($value instanceof RenderableInterface)
    {
      return new Response($value->render());
    }

    if(is_callable($value))
    {
      return $value();
    }

    if(is_object($value))
    {
      return new CubexResponse($value);
    }

    return null;
  }

  /**
   * Execute a method, and convert the response or output into a response
   *
   * @param            $method
   * @param null|array $params Parameters to send into the method
   *
   * @return \Cubex\Http\Response|null
   */
  protected function _getMethodResult($method, $params = null)
  {
    ob_start();
    if($params === null)
    {
      $response = $this->$method();
    }
    else
    {
      $response = call_user_func_array([$this, $method], $params);
    }
    return $this->handleResponse($response, ob_get_clean());
  }

  /**
   * Attempt to convert a string to a callable
   *
   * @param $routeValue
   *
   * @return callable|null
   */
  public function attemptCallable($routeValue)
  {
    if(stristr($routeValue, ','))
    {
      $routeValue = explode(',', $routeValue);
    }
    else if(stristr($routeValue, '@'))
    {
      $routeValue = explode('@', $routeValue);
    }

    if(is_callable($routeValue))
    {
      return $routeValue;
    }

    return null;
  }

  /**
   * Search the current class for a matching method
   *
   * @param         $routeValue
   * @param Request $request
   *
   * @return null
   */
  public function attemptMethod($routeValue, Request $request)
  {
    $method     = trim($routeValue);
    $callMethod = ucwords(str_replace(['-', '_'], ' ', $method));
    $callMethod = str_replace(' ', '', $callMethod);

    $attempts = [];
    //Handle Ajax Requests
    if($request->isXmlHttpRequest())
    {
      $attempts[] = 'ajax' . $callMethod;
    }

    if($request->isMethod('POST'))
    {
      $attempts[] = 'post' . $callMethod;
    }

    $attempts[] = 'render' . $callMethod;
    $attempts[] = 'action' . $callMethod;
    $attempts[] = $method;

    foreach($attempts as $attempt)
    {
      if(method_exists($this, $attempt))
      {
        return $attempt;
      }
    }
    return null;
  }

  /**
   * Check the route for a redirect rule
   *
   * @param         $routeValue
   * @param Request $request
   *
   * @return array|null
   */
  public function attemptUrl($routeValue, Request $request = null)
  {
    //Match full urls, status code and urls, and relative urls.
    if(preg_match('/^(.*tps?:\/\/|\/|#@|@[0-9]{3}\!)/', $routeValue))
    {
      //If specified, use the http code provided e.g. @301!http://google.com
      if(substr($routeValue, 0, 1) == '@')
      {
        list($code, $url) = sscanf($routeValue, "@%d!%s");
        return ['code' => $code, 'url' => $url];
      }
      else if(substr($routeValue, 0, 2) == '#@')
      {
        //Starting a url with #@ implies a redirect.  Useful for redirecting
        if($request !== null && substr($request->getPathInfo(), -1) == '/')
        {
          //If there is a leading slash, make sure you put them up a directory
          $routeValue = '../' . substr($routeValue, 2);
        }
        else
        {
          //to relative urls e.g. /homepage or about
          $routeValue = substr($routeValue, 2);
        }
      }
      return ['code' => 302, 'url' => $routeValue];
    }
    return null;
  }

  public function handleResponse($response, $capturedOutput)
  {
    if(is_object($response))
    {
      return $response;
    }

    if($response !== null && !empty($response))
    {
      return new CubexResponse($response);
    }

    if($capturedOutput !== null && !empty($capturedOutput))
    {
      return new CubexResponse($capturedOutput);
    }

    return null;
  }

  /**
   * Auto build the result based on the URL
   *
   * @param Request $request
   * @param int     $type
   * @param bool    $catch
   *
   * @return \Cubex\Http\Response|null|Response
   */
  public function autoRoute(
    Request $request, $type = self::MASTER_REQUEST, $catch = true
  )
  {
    $params      = null;
    $paramString = trim($request->getPathInfo(), '/');
    if(!$paramString)
    {
      return null;
    }

    $params = explode('/', $paramString);
    $params = array_slice($params, $this->_routeLevel);
    $path   = array_shift($params);

    if(empty($params))
    {
      $params = null;
    }

    //Does the method exist?
    $method = $this->attemptMethod($path, $request);
    if($method !== null)
    {
      return $this->_processResponse(
        $this->_getMethodResult($method, $params),
        $request,
        $type,
        $catch,
        $params
      );
    }

    return $this->attemptSubClass($path, $request, $type, $catch, $params);
  }

  /**
   *
   * @param         $part
   * @param Request $request
   * @param int     $type
   * @param bool    $catch
   * @param array   $params
   *
   * @return null|Response
   */
  public function attemptSubClass(
    $part, Request $request, $type = self::MASTER_REQUEST, $catch = true,
    array $params = null
  )
  {
    $classPath = ucfirst($part);
    $namespace = get_namespace(get_called_class());
    $subRoutes = $this->subRouteTo();

    //No subroutes available
    if($subRoutes === null || empty($subRoutes))
    {
      return null;
    }

    foreach($subRoutes as $subRoute)
    {
      $classPath = ucwords(str_replace(['-', '_'], ' ', $classPath));
      $classPath = str_replace(' ', '', $classPath);

      //Half sprintf style, but changed to str_replace for multiple instances
      $attempt = build_path_win(
        $namespace,
        str_replace('%s', $classPath, $subRoute)
      );

      if(class_exists($attempt))
      {
        $manager = new $attempt;

        if($manager instanceof ICubexAware)
        {
          $manager->setCubex($this->getCubex());
        }

        if($manager instanceof CubexKernel)
        {
          $manager->_routeLevel    = $this->_routeLevel + 1;
          $manager->_processParams = $params;
        }

        if($manager instanceof HttpKernelInterface)
        {
          return $manager->handle($request, $type, $catch);
        }

        return $this->handleResponse($manager, null);
      }
    }

    return null;
  }

  /**
   * Retrieve a cubex configuration item
   *
   * @param      $section
   * @param      $item
   * @param null $default
   *
   * @return mixed
   */
  public function getConfigItem($section, $item, $default = null)
  {
    return $this->getCubex()->getConfiguration()->getItem(
      $section,
      $item,
      $default
    );
  }

  public function subRouteTo()
  {
    return null;
  }
}
