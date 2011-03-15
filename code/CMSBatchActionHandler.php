<?php

/**
 * Special request handler for admin/batchaction
 *  
 * @package cms
 * @subpackage batchaction
 */
class CMSBatchActionHandler extends RequestHandler {
	
	static $batch_actions = array();
	
	static $url_handlers = array(
		'$BatchAction/applicablepages' => 'handleApplicablePages',
		'$BatchAction/confirmation' => 'handleConfirmation',
		'$BatchAction' => 'handleAction',
	);
	
	protected $parentController;
	
	/**
	 * @var String
	 */
	protected $urlSegment;
	
	/**
	 * @var String $recordClass The classname that should be affected
	 * by any batch changes. Needs to be set in the actual {@link CMSBatchAction}
	 * implementations as well.
	 */
	protected $recordClass = 'SiteTree';
	
	/**
	 * Register a new batch action.  Each batch action needs to be represented by a subclass
	 * of {@link CMSBatchAction}.
	 * 
	 * @param $urlSegment The URL Segment of the batch action - the URL used to process this
	 * action will be admin/batchactions/(urlSegment)
	 * @param $batchActionClass The name of the CMSBatchAction subclass to register
	 */
	static function register($urlSegment, $batchActionClass, $recordClass = 'SiteTree') {
		if(is_subclass_of($batchActionClass, 'CMSBatchAction')) {
			self::$batch_actions[$urlSegment] = array(
				'class' => $batchActionClass,
				'recordClass' => $recordClass
			);
		} else {
			user_error("CMSBatchActionHandler::register() - Bad class '$batchActionClass'", E_USER_ERROR);
		}
	}
	
	/**
	 * @param string $parentController
	 * @param string $urlSegment
	 * @param string $recordClass
	 */
	function __construct($parentController, $urlSegment, $recordClass = null) {
		$this->parentController = $parentController;
		$this->urlSegment = $urlSegment;
		if($recordClass) $this->recordClass = $recordClass;
		
		parent::__construct();
	}
	
	function Link() {
		return Controller::join_links($this->parentController->Link(), $this->urlSegment);
	}

	function handleAction($request) {
		// This method can't be called without ajax.
		if(!$this->parentController->isAjax()) {
			$this->parentController->redirectBack();
			return;
		}
		
		// Protect against CSRF on destructive action
		if(!SecurityToken::inst()->checkRequest($request)) return $this->httpError(400);

		$actions = $this->batchActions();
		$actionClass = $actions[$request->param('BatchAction')]['class'];
		$actionHandler = new $actionClass();
		
		// Sanitise ID list and query the database for apges
		$ids = split(' *, *', trim($request->requestVar('csvIDs')));
		foreach($ids as $k => $v) if(!is_numeric($v)) unset($ids[$k]);
		
		if($ids) {
			if(Object::has_extension('SiteTree','Translatable')) Translatable::disable_locale_filter();
			
			$pages = DataObject::get(
				$this->recordClass, 
				sprintf(
					'"%s"."ID" IN (%s)',
					ClassInfo::baseDataClass($this->recordClass),
					implode(", ", $ids)
				)
			);
			
			if(Object::has_extension('SiteTree','Translatable')) Translatable::enable_locale_filter();
			
			if(Object::has_extension($this->recordClass, 'Versioned')) {
				// If we didn't query all the pages, then find the rest on the live site
				if(!$pages || $pages->Count() < sizeof($ids)) {
					foreach($ids as $id) $idsFromLive[$id] = true;
					if($pages) foreach($pages as $page) unset($idsFromLive[$page->ID]);
					$idsFromLive = array_keys($idsFromLive);

					$sql = sprintf(
						'"%s"."ID" IN (%s)',
						$this->recordClass,
						implode(", ", $idsFromLive)
					);
					$livePages = Versioned::get_by_stage($this->recordClass, 'Live', $sql);
					if($pages) $pages->merge($livePages);
					else $pages = $livePages;
				}
			}
		} else {
			$pages = new DataObjectSet();
		}
		
		return $actionHandler->run($pages);
	} 

	function handleApplicablePages($request) {
		// Find the action handler
		$actions = Object::get_static($this->class, 'batch_actions');
		$actionClass = $actions[$request->param('BatchAction')];
		$actionHandler = new $actionClass['class']();

		// Sanitise ID list and query the database for apges
		$ids = split(' *, *', trim($request->requestVar('csvIDs')));
		foreach($ids as $k => $id) $ids[$k] = (int)$id;
		$ids = array_filter($ids);
		
		if($actionHandler->hasMethod('applicablePages')) {
			$applicableIDs = $actionHandler->applicablePages($ids);
		} else {
			$applicableIDs = $ids;
		}
		
		$response = new SS_HTTPResponse(json_encode($applicableIDs));
		$response->addHeader("Content-type", "application/json");
		return $response;
	}
	
	function handleConfirmation($request) {
		// Find the action handler
		$actions = Object::get_static($this->class, 'batch_actions');
		$actionClass = $actions[$request->param('BatchAction')];
		$actionHandler = new $actionClass();

		// Sanitise ID list and query the database for apges
		$ids = split(' *, *', trim($request->requestVar('csvIDs')));
		foreach($ids as $k => $id) $ids[$k] = (int)$id;
		$ids = array_filter($ids);
		
		if($actionHandler->hasMethod('confirmationDialog')) {
			$response = new SS_HTTPResponse(json_encode($actionHandler->confirmationDialog($ids)));
		} else {
			$response = new SS_HTTPResponse(json_encode(array('alert' => false)));
		}
		
		$response->addHeader("Content-type", "application/json");
		return $response;
	}
	
	/**
	 * Return a DataObjectSet of ArrayData objects containing the following pieces of info
	 * about each batch action:
	 *  - Link
	 *  - Title
	 */
	function batchActionList() {
		$actions = $this->batchActions();
		$actionList = new DataObjectSet();
		
		foreach($actions as $urlSegment => $action) {
			$actionClass = $action['class'];
			$actionObj = new $actionClass();
			if($actionObj->canView()) {
				$actionDef = new ArrayData(array(
					"Link" => Controller::join_links($this->Link(), $urlSegment),
					"Title" => $actionObj->getActionTitle(),
				));
				$actionList->push($actionDef);
			}
		}
		
		return $actionList;
	}
	
	/**
	 * Get all registered actions through the static defaults set by {@link register()}.
	 * Filters for the currently set {@link recordClass}.
	 * 
	 * @return array See {@link register()} for the returned format.
	 */
	function batchActions() {
		$actions = Object::get_static($this->class, 'batch_actions');
		if($actions) foreach($actions as $action) {
			if($action['recordClass'] != $this->recordClass) unset($action);
		}
		
		return $actions;
	}

}