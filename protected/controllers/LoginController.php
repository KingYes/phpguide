<?php

class LoginController extends Controller
{

    /**
     * Displays Log-in page
     */
    public function actionIndex()
    {
        $return_location = Yii::app()->request->getQuery('redir',   Yii::app()->homeUrl );
        $this->addscripts('login');

        $this->pageTitle = 'הזדהות לאתר לימוד PHP';
        $this->description = 'עמוד הזדהות וכניסה למערכת';
        $this->keywords = 'הזדהות';

        $this->render('login', array('return_location' => $return_location));  
    }

    
    /**
     * Password recovery form and validation
     */
    public function actionRecover()
    {
    	if(Yii::app()->request->getIsAjaxRequest())
    	{
    		$login = Yii::app()->request->getPost('login');
    		$email = Yii::app()->request->getPost('email');
    		
    		if( '' === trim($login)  ||  '' === trim($email) )
    		{
    			echo 'יש להזין אימייל ושם משתמש כלשהם';
    		}
    		else
    		{
    			try
    			{
    				$user = User::model()->findByAttributes(array('login' => $login, 'email' => $email));
    				if(null === $user)
    				{
    					echo 'לא נמצא משתמש כזה במערכת';
    				}
    				else
    				{
    					$pwr = new PasswordRecovery();
    					$pwr->userid = $user->id;
    					$pwr->ip = Yii::app()->request->getUserHostAddress();
    					$pwr->key = Helpers::randString(20);
    					$pwr->validity = new CDbExpression('DATE_ADD(NOW(), INTERVAL 1 HOUR)');
    					$pwr->save();
    					
    					$recovery_url = bu('login/recoverykey?id='.$pwr->id . '&key='.$pwr->key, true);
    					
    					$mail = $this->renderPartial('recoveryMail', array('username' => $user->login, 'recovery_url' => $recovery_url), true);
    					Helpers::sendMail($user->email, "שחזור סיסמה באתר phpguide", $mail);
    					
    					echo 'מייל עם הוראות לשחזור סיסמה נשלח לכתובת המייל שלך';
    				}
    			}
    			catch(Exception $e)
    			{
    				echo 'חלה שגיאה לא מוכרת כלשהי. נסו שוב מאוחר יותר';
    				Yii::log("Password recovery error: " . $e->getMessage(), CLogger::LEVEL_ERROR);
    			}
    		}
    	}
    	else
    	{
    		
    		$this->pageTitle = 'שחזור סיסמה';
    		$this->description = 'שחזור סיסמה באתר לימוד PHP';
    		$this->keywords = 'שחזור, סיסמה';
    		
    		$this->addscripts('login');
    		$this->render('passwordRecovery');
    	}
    }

    
    /**
     * Action to be called when a user clicks recover password link in the mail
     */
    public function actionRecoverykey()
    {
    	$id = Yii::app()->request->getQuery('id');
    	$key = Yii::app()->request->getQuery('key');
    	
    	$pwr = PasswordRecovery::model()->with('user')->findByPk($id, '`key`=:key and validity > NOW()', array('key' => $key));
    	
    	if( null === $pwr || null === $pwr->user)
    	{
    		$pwr->delete();
    		$this->redirect(Yii::app()->homeUrl);
    	}
    	
    	$identity = new AuthorizedIdentity($pwr->user);
    	Yii::app()->user->login($identity, Yii::app()->params['login_remember_me_duration']);
    	
    	$this->addscripts('login');
    	Yii::app()->clientScript->registerScript('homepage', 'var homepage_url="'.Yii::app()->homeUrl.'"; ', CClientScript::POS_END);
    	$this->render('changePassword');
    }
    
    
    public function actionChangepw()
    {
    	if(Yii::app()->request->getIsAjaxRequest() && !Yii::app()->user->isguest)
    	{
    		$password = Yii::app()->request->getPost('pass');
    		if(empty($password)) return;
    		
    		$salt = Helpers::randString(22);
    		$password = WebUser::encrypt_password($password, $salt);
    		
    		User::model()->updateByPk(Yii::app()->user->id, array('password' => $password, 'salt' => $salt));
    		Yii::app()->user->setFlash('successPwChange', 'סיסמתך שונת בהצלחה');    
    	}
    }
    
    
    /**
     * Regular log-in action, called via ajax submit from the login form.
     * @throws CHttpException 
     */
    public function actionLogin()
    {
        if(!Yii::app()->request->getIsAjaxRequest())
        {
            throw new CHttpException(400, 'This request available via ajax only');
        }
        
        $username = Yii::app()->request->getPost('user');
        $password = Yii::app()->request->getPost('pass');
        
        if(empty($username) || empty($password))
        {
            echo 'שם משתמש או סיסמה שגויים';
        }
        else
        {
            try
            {
                $identity = new DbUserIdentity($username, $password); 
                if($identity->authenticate())
                {
                    Yii::app()->user->login($identity, Yii::app()->params['login_remember_me_duration']);
                    
                    // if authentication takes place after external auth, we want to attach the external Id to the account we are authenticating
                    $this->updateExternalAuthInfo();
                    
                    echo 'ok';
                }
                else 
                {
                    echo('שם משתמש או סיסמה שגויים');
                }
            }
            catch(BruteForceException $e)
            {
                echo('ביצעתם יותר מדי נסניונות התחברות. נסו שוב בעוד שעה');
            }
            catch(Exception $e)
            {
            	echo 'חלה תקלה בתהליך ההזדהות. נסו שום בעוד כמה דקות';
            	Yii::log("Login error: " . $e->getMessage(), CLogger::LEVEL_ERROR);
            	
            }
        }
    }
    
    
    
    
    
    
     /**
     * Fired when the user decides to login with external auth provider.  
     */
    public function actionExternalLogin()
    {
    	
        if (isset($_GET['service'])) 
        {
        	$backto = Yii::app()->request->getQuery('backto');
        	if($backto) Yii::app()->session['backto'] =  $backto;
            $this->authWithExternalProvider($_GET['service']);
        }
        else
        {
            $this->redirect(array('index'));
        }
    }
    
    
    /**
     * Attempts to authenticate the user using external oAuth provider
     * @param type $providerName 
     */
    private function authWithExternalProvider($providerName)
    {
        $authenticator = Yii::app()->eauth->getIdentity($providerName);

        if($authenticator->authenticate() && $authenticator->isAuthenticated) 
        {
            $this->externalAuthSucceeded($authenticator);
        }
        else
        {
            $this->externalAuthFailed($authenticator);
        }
    }
    
    
    
    /**
     * Called on external Authentication success
     * @param IAuthService $provider 
     */
    private function externalAuthSucceeded(IAuthService $provider)
    {
       $identity = new ServiceUserIdentity($provider) ;
       $data = $provider->getAttributes();
       
       // Did someone use this external ID in the past?
       if($identity ->isKnownUser() )
       {
           Yii::app()->user->login($identity);
           $this->redirect(Yii::app()->session['backto'] ?: array('homepage/index'));
       }
       // external auth succeeded, but we don't know whom do this external ID belongs to
       else
       {
       		$userInfo = $provider->getAttributes();
       		$externalAuthProviders = Yii::app()->session['externalAuth'];
       		
       		if($externalAuthProviders === null) $externalAuthProviders = array();
       		$externalAuthProviders[$provider -> serviceName] = $userInfo;
       		Yii::app()->session['externalAuth'] = $externalAuthProviders;
       		
       		$return_location = Yii::app()->session['backto'] ?: Yii::app()->request->getQuery('redir',   Yii::app()->homeUrl );
       		$this->addscripts('login');
       		$this->render('chooseNameAfterExternalLogin', array('provider' => $provider->serviceName, 'name' => $userInfo['name'], 'return_location' => $return_location));
       		
       }
    }
    
    private function externalAuthFailed(IAuthService $serviceAuthenticator)
    {
        Yii::app()->user->setFlash('externalAuthFail', 'הזדהות באמצעות ' . $serviceAuthenticator->getServiceName() . ' נכשלה');
        $this->redirect(array('login/index'));
    }

    
    
    public function actionLogout()
    {
        Yii::app()->user->logout();
        $this->redirect(array('homepage/index'));
    }

    
    public function actionRegister()
    {
        $username = Yii::app()->request->getPost('reguser');
        $email = Yii::app()->request->getPost('regemail');
        
        
        try
        {              
        	$externalAuthData = Yii::app()->session['externalAuth'];
        	
        	// Allow registration only using oAuth external services
        	if(!is_array($externalAuthData) || sizeof($externalAuthData) < 1)
        	{
        		echo 'הרשמה ניתנן באמצעות פייסבוק';
        		return;
        	}
        	
        	// registration of new user means taking the existing, unregistered one and updating his name and info
            $user = new User();
            $user->scenario = 'register';
            $user->attributes = array('login' => $username, 'email' => $email);
            $user->reg_date = new SDateTime();
            $user->last_visit = new SDateTime();
            $user->salt = Helpers::randString(22);
            $user->password = WebUser::encrypt_password( Helpers::randString(22), $user->salt);
            $user->ip = Yii::app()->request->getUserHostAddress();
            

            try
            {
                $user->save();
            }
            catch(CDbException $e)
            {
                throw (false !== mb_strpos($e->getMessage(), 'Duplicate') ) ? new UsernameAlreadyTaken() : $e;
            }
            
            $allErrors = array();
            $errors = $user->getErrors();
            
            if(sizeof($errors) > 0)
            {
                foreach($errors as $fieldErrors)
                {
                    $allErrors = array_merge($allErrors, $fieldErrors);
                }
                echo '— ' . nl2br(e(implode("\r\n — ", $allErrors)));
            }
            else
            {
                $identity = new AuthorizedIdentity($user);
                Yii::app()->user->login($identity, Yii::app()->params['login_remember_me_duration']);
                $this->updateExternalAuthInfo();               
                echo 'ok';
            }
        }
        catch (UsernameAlreadyTaken $e)
        {
            echo  'שם משתמש זה תפוס';
        }
        catch (Exception $e)
        {
            echo 'שגיאת שרת בתהליך ההרשמה. אנה נסו במועד מאוחר יותר';
            Yii::log("Signup error : " . $e->getMessage(), CLogger::LEVEL_ERROR);
            
        }
        

    }
    
    
    /**
     * Takes external auth data from session 
     * and updates the user record with the corresponding external ID's
     */
    private function updateExternalAuthInfo()
    {
    	$externalAuthenticatedProviders = Yii::app()->session['externalAuth'];
    	if(is_array($externalAuthenticatedProviders) && sizeof($externalAuthenticatedProviders) > 0)
    	{
    		$userUpdateData = array();
    		$userInfoUpdateData = array();
    	
    		foreach($externalAuthenticatedProviders as $serviceName => $userinfo)
    		{
    			$userUpdateData[ ServiceUserIdentity::$service2fieldMap[$serviceName] ] = $userinfo['id'];
    			$userInfoUpdateData['real_name'] = $userinfo['name'];
    		}
    		$user = User::model()->updateByPk(Yii::app()->user->id, $userUpdateData);
    		return true;
    	}
    	else
    	{
    		return false;
    	}
    }

}


/**
 * Indicates the authentication attempt has been blocked to avoid brute-force 
 */
class UsernameAlreadyTaken extends Exception{}