<?
namespace Concrete\Core\Routing;
use Request;
use User;
use Events;
use Loader;
use Page;
use Config;
use Permissions;

class DispatcherRouteCallback extends RouteCallback {
	
	protected function sendResponse(View $v, $code = 200) {
		$contents = $v->render();
		$response = new Response($contents, $code);
		return $response;
	}

	protected function sendPageNotFound(Request $request) {
		$item = '/page_not_found';
		$c = Page::getByPath($item);
		if (is_object($c) && !$c->isError()) {
			$item = $c;
			$request->setCurrentPage($c);
		}
		$cnt = Loader::controller($item);
		$v = $cnt->getViewObject();
		$cnt->on_start();
		$cnt->runAction('view');
		$v->setController($cnt);
		return $this->sendResponse($v, 404);
	}

	protected function sendPageForbidden(Request $request) {
		$item = '/page_forbidden';
		$c = Page::getByPath($item);
		if (is_object($c) && !$c->isError()) {
			$item = $c;
			$request->setCurrentPage($c);
		}
		$cnt = Loader::controller($item);
		$v = $cnt->getViewObject();
		$cnt->on_start();
		$cnt->runAction('view');
		$v->setController($cnt);
		return $this->sendResponse($v, 403);
	}

	public function execute(\Concrete\Core\Http\Request $request, \Concrete\Core\Routing\Route $route, $parameters) {
		
		// figure out where we need to go
		$c = Page::getFromRequest($request);
		if ($c->isError() && $c->getError() == COLLECTION_NOT_FOUND) {
			// if we don't have a path and we're doing cID, then this automatically fires a 404.
			if (!$request->getPath() && $request->get('cID')) {
				return $this->sendPageNotFound($request);
			}
			// let's test to see if this is, in fact, the home page,
			// and we're routing arguments onto it (which is screwing up the path.)
			$home = Page::getByID(HOME_CID);
			$homeController = Loader::controller($home);
			$homeController->setupRequestActionAndParameters($request);
			if (!$homeController->validateRequest()) {
				return $this->sendPageNotFound($request);
			} else {
				$c = $home;
			}
		}
		
		// maintenance mode
		if ((!$c->isAdminArea()) && ($c->getCollectionPath() != '/login')) {
			$smm = Config::get('SITE_MAINTENANCE_MODE');
			if ($smm == 1 && ($_SERVER['REQUEST_METHOD'] != 'POST' || Loader::helper('validation/token')->validate() == false)) {
				$v = new View('/maintenance_mode');
				$v->setViewTheme(VIEW_CORE_THEME);
				return $this->sendResponse($v);
			}
		}

		// Check to see whether this is an external alias or a header 301 redirect. If so we go there.
		/*
		if (($request->getPath() != '') && ($request->getPath() != $c->getCollectionPath())) {
			// canonnical paths do not match requested path
			return Redirect::page($c, 301);
		}
		*/

		if ($c->getCollectionPointerExternalLink() != '') {
			return Redirect::url($c->getCollectionPointerExternalLink(), 301)->send();
		}

		$cp = new Permissions($c);

		if ($cp->isError() && $cp->getError() == COLLECTION_FORBIDDEN) {
			return $this->sendPageForbidden($request);
		}

		if (!$c->isActive() && (!$cp->canViewPageVersions())) {
			return $this->sendPageNotFound($request);
		}

		if ($cp->canEditPageContents() || $cp->canEditPageProperties() || $cp->canViewPageVersions()) {
			$c->loadVersionObject('RECENT');
		}

		$vp = new Permissions($c->getVersionObject());

		// returns the $vp object, which we then check
		if (is_object($vp) && $vp->isError()) {
			switch($vp->getError()) {
				case COLLECTION_NOT_FOUND:
					return $this->sendPageNotFound($request);
					break;
				case COLLECTION_FORBIDDEN:
					return $this->sendPageForbidden($request);
					break;
			}
		}

		$request->setCurrentPage($c);
		require(DIR_BASE_CORE . '/startup/process.php');
		$u = new User();
		if (STATISTICS_TRACK_PAGE_VIEWS == 1) {
			$u->recordView($c);
		}

		## Fire the on_page_view Eventclass
		Events::fire('on_page_view', $c, $u);

		$controller = Loader::controller($c);
		$controller->on_start();
		$controller->setupRequestActionAndParameters($request);
		$requestTask = $controller->getRequestAction();
		$requestParameters = $controller->getRequestActionParameters();
		if (!$controller->validateRequest()) {
			return $this->sendPageNotFound($request);
		}
		$controller->runAction($requestTask, $requestParameters);
		$c->setController($controller);
		$view = $controller->getViewObject();
		// we update the current page with the one bound to this controller.
		$request->setCurrentPage($c);
		return $this->sendResponse($view);
	}

	public static function getRouteAttributes($callback) {
		$callback = new DispatcherRouteCallback($callback);
		return array('callback' => $callback);
	}


}
