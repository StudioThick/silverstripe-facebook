<?php

/**
 * FacebookContoller provides the integration functionality for the front-end
 *
 * @package silverstripe-facebook
 * @subpackage control
**/
class FacebookController extends ContentController {

	static $allowed_actions = array(
		"connect" => "->facebookLoginEnabled",
		"login" => "->facebookLoginEnabled",
		"disconnect",
	);


	public function facebookLoginEnabled() {
		$facebookApp = FacebookApp::get()->first();
		return $facebookApp->EnableFacebookLogin;
	}

	/**
	 * Return a blank form to display front-end messages
	 *
	 * @return Form
	**/
	public function Form() {
		$form = new Form($this, "Facebook", new FieldList(), new FieldList());
		$this->extend("setupForm", $form);
		return $form;
	}

	/**
	* Prevent this request as it doesn't do anything.
	*
	* @return SS_HTTPResponse
	**/
	public function index() {
		return $this->httpError(403, "Forbidden");
	}


	/** 
	 * This will connect a facebook account to a logged in Member.
	 *
	 * @param $request SS_HTTPRequest
	 * @return SS_HTTPResponse
	**/
	public function connect($request) {
		$form = $this->Form();
		$member = Member::currentUser();
		
		if($this->request->getVar("error")) {
			$form->sessionMessage("Oops. Unable to access Facebook. Try again.", "bad");
			return $this->renderWith(array("FacebookController", "Page", "Controller"));
		}


		$facebookApp = FacebookApp::get()->first();
		if($member || $facebookApp->EnableFacebookLogin) {
			$facebook = $facebookApp->getFacebook();
			if(!$facebook) {
				$form->sessionMessage("Oops. Unable to fetch Facebook Application. Try again soon.", "bad");
				return $this->renderWith(array("FacebookController", "Page", "Controller"));
			}

			$user = $facebook->getUser();
			if(!$user) {
				$params = $facebookApp->getLoginUrlParams();
				$url = $facebook->getLoginUrl($params);
				if($url) {
					return $this->redirect($url, 302);
				} else {
					$form->sessionMessage("Oops. Unable to login to Facebook. Check your Facebook permissions.", "bad");
				}
			} else {
				$user_profile = $facebook->api("/me");
				if($user_profile) {
					// Check whether this is a new user (signup)
					$member = Member::get()->filter("FacebookUserID", $user_profile['id'])->first();

					if(!$member && isset($user_profile["email"])){
						$member = Member::get()->filter("Email", $user_profile['email'])->first();
					}

					if($member) {
						return $this->redirect(Controller::join_links("facebook", "login"));
					} else {
						$member = new KeepCupMember();
						$access_token = Session::get("fb_" . $facebookApp->FacebookConsumerKey . "_access_token");
						$user_friends = $facebook->api("me/friends?fields=installed,first_name,last_name,id");
						if($user_friends){
							$user_profile["friends_count"] = count($user_friends["data"]);
						}
						$valid = $member->connectFacebookAccount($user_profile, $access_token);
						if($valid->valid()) {
							$form->sessionMessage("Success! Signed up with Facebook.", "good");
							$this->extend("onAfterFacebookSignup", $member);
						} else {
							$form->sessionMessage($valid->message(), "bad");
						}
					}
				} else {
					$form->sessionmessage("Oops. Unable to retrieve Facebook account.  Check your Facebook permissions.", "bad");
				}
			}
		} else {
			$form->sessionMessage("You must be logged in to connect your Facebook account.", "bad");
		}
		return $this->redirect("/");
		//return $this->renderWith(array("FacebookController", "Page", "Controller"));
	}

	/**
	 * This will disconnect a members' Facebook account from their SS account.
	 *
	 * @param $request SS_HTTPRequest
	 * @return SS_HTTPResponse
	**/
	public function disconnect($request) {
		$form = $this->Form();
		$member = Member::currentUser();

		if($member) {
			$member->disconnectFacebookAccount();
			$this->extend("onAfterFacebookDisconnect");
		}
		$form->sessionMessage("You have disconnected your account.", "good");

		return $this->renderWith(array("FacebookController", "Page", "Controller"));
	}


	/**
	 * Log the user in via an existing Facebook account connection.
	 *
	 * @return SS_HTTPResponse
	**/
	public function login() {
		$form = $this->Form();
		
		if($this->request->getVar("error")) {
			$form->sessionMessage("Oops. Unable to access Facebook. Try again..", "bad");
			return $this->renderWith(array("FacebookController", "Page", "Controller"));
		}

		$facebookApp = FacebookApp::get()->first();
		if(!$facebookApp || !$facebookApp->EnableFacebookLogin) {
			$form->sessionMessage("Facebook Login is disabled.", "bad");
		} else {
			if($member = Member::currentUser())
				$member->logOut();

			$facebook = $facebookApp->getFacebook();
			$user = $facebook->getUser();
			if($user) {

				$user_profile = $facebook->api("/me");

				$member = Member::get()->filter("FacebookUserID", $user_profile['id'])->first();

				if(!$member && isset($user_profile["email"])){
					$member = Member::get()->filter("Email", $user_profile['email'])->first();
				}

				if($member) {
					$member->logIn();
					$form->sessionMessage("Success! Logged in with Facebook.", "good");
					$member->extend("onAfterMemberLogin");
				} else if ($facebookApp->EnableFacebookSignup) {
					// Attempt to sign the user up.
					$member = new KeepCupMember();

					// Load the user from Faceook
					$user_profile = $facebook->api("/me");

					if($user_profile) {
						// Fill in the required fields.
						$access_token = Session::get("fb_" . $facebookApp->FacebookConsumerKey . "_access_token");
						$user_friends = $facebook->api("me/friends?fields=installed,first_name,last_name,id");
						if($user_friends){
							$user_profile["friends_count"] = count($user_friends["data"]);
						}
						$signup = $member->connectFacebookAccount($user_profile, $access_token, $facebookApp->config()->get("required_user_fields"));
						if($signup->valid()) {
							$member->logIn();
							$form->sessionMessage("Success! Signed up with Facebook.", "good");

							// Facebook Hooks
							$this->extend("onAfterFacebookSignup", $member);
						} else {
							$form->sessionMessage($signup->message(), "bad");
						}
					} else {
						$form->sessionMessage("Oops. Unable to retrieve Facebook account.  Check your Facebook permissions.", "bad");
					}
				} else {
					$form->sessionMessage("Oops. Unable to login to Facebook. Check your Facebook permissions.", "bad");
				}
			} else {
				$params  = $facebookApp->getLoginUrlParams();
				$url = $facebook->getLoginUrl($params);
				if($url) {
					return $this->redirect($url, 302);
				} else {
					$form->sessionMessage("Oops. Unable to login to Facebook. Check your Facebook permissions.", "bad");
				}
			}
		}

		// Extend Failed facebook login
		if(!Member::currentUser()) $this->extend("onAfterFailedFacebookLogin");
		return $this->redirect("/");
		//return $this->renderWith(array("FacebookController", "Page", "Controller"));
	}
}

