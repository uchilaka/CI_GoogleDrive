<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/*
 * The MIT License
 *
 * Copyright 2014 uchilaka.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */


/** 
 * This script requires the following files from the google_api_php_client library
 * src/Google_Client.php
 * src/contrib/Google_DriveService.php
 * 
 * **/
define('APP_NAME', 'your_app_name');

require_once APPPATH .  'third_party' . DS . 'google' . DS . 'google-api-php-client' . DS . 'src' . DS . 'Google_Client.php';
require_once APPPATH . 'third_party' . DS . 'google' . DS . 'google-api-php-client' . DS . 'src' . DS . 'contrib' . DS . 'Google_Oauth2Service.php';
require_once APPPATH . 'third_party' . DS . 'google' . DS . 'google-api-php-client' . DS . 'src' . DS . 'contrib' . DS . 'Google_DriveService.php';

class GoogleDrive2Exception extends Exception {
    public function __construct($message, $code, $previous) {
        parent::__construct($message, $code, $previous);
        die("GoogleDrive2 Library Exception:: " . $message);
    }
}

class google_drive {

    /** This is the session key for storing your google drive access token **/
    const TOKEN_KEY = "shdwbx.gdrive.access_token";
    const PERMISSION_PUBLIC = "public";
    const PERMISSION_PRIVATE = "private";
    const SYSDIR = 'shdwbx.drive';
    var $_client_id;
    /** Keeping client secret private **/
    private $CI;
    private $_client_secret;
    var $_redirect_uri;
    
    var $OAuth2Service;
    var $Client;
    var $Scopes;
    var $Service;
    var $Tokens;
    var $Ready = FALSE;
    
    public function __construct($config = ['client_id'=>null, 'client_secret'=>null, 'redirect_uri'=>null]) {
        $this->CI =& get_instance();
        $this->CI->load->library('curl');
        //print_r($config);
        $this->Scopes = [
            'https://www.googleapis.com/auth/drive',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile'
        ];
        $required_config=['client_id','client_secret','redirect_uri'];
        // throw error if config is not passed with google console app parameters
        foreach($required_config as $key):
            if(empty($config[$key])):
                throw new GoogleDrive2Exception("config key `{$key}` is required");
            endif;
        endforeach;
        
        $config = (object)$config;
        $this->Client = new Google_Client();
        $this->Client->setClientId($config->client_id);
        $this->Client->setClientSecret($config->client_secret);
        $this->Client->setRedirectUri($config->redirect_uri);
        $this->Client->setScopes($this->Scopes);
        $this->Client->setUseObjects(true);
        // initialize service
        $this->Service = new Google_DriveService($this->Client);
        $this->OAuth2Service = new Google_Oauth2Service($this->Client);
        
        $this->Tokens = $this->CI->session->userdata(self::TOKEN_KEY);
        if($this->Tokens) {
            $this->Client->setAccessToken($this->Tokens);
        } else if($code = $this->CI->input->get('code',TRUE)) {
            $this->Client->authenticate($code);
            $this->Tokens = $this->Client->getAccessToken();
        } else {
            // no code, and no available token
            if(!empty($config->redirect_uri)) {
                // redirect for authorization
                redirect($this->Client->createAuthUrl());
            }
        }
        // check to make sure access token is not expired
        if($this->Client->isAccessTokenExpired() and $this->Tokens) {
            $tokens = json_decode($this->Tokens);
            $refreshToken = $tokens->refresh_token;
            $this->Client->refreshToken($refreshToken);
            $this->Tokens = $this->Client->getAccessToken();
        }
        // save access token
        if(!$this->Client->isAccessTokenExpired()) {
            $this->CI->session->set_userdata(self::TOKEN_KEY, $this->Tokens);
            $this->Ready = true;
        } else {
            $this->Ready = false;
        }
    }
    
    public function getUser() {
        if(!$this->Client) {
            throw new GoogleDrive2Exception("You MUST initialize the Google_Client before attempting getUser()");
        }
        return $this->OAuth2Service->userinfo->get();
    }
    
    public function logout() {
        $this->CI->session->unset_userdata(self::TOKEN_KEY);
    }
    
    public function getFilePermissions($allow=self::PERMISSION_PRIVATE) {
        $permission = new Google_Permission();
        switch($allow):
            case self::PERMISSION_PRIVATE:
                $permission->setValue('me');
                $permission->setType('default');
                $permission->setRole('owner');
                break;

            default:
                $permission->setValue('');
                $permission->setType('anyone');
                $permission->setRole('reader');
                break;
        endswitch;
        return $permission;
    }
    
    public function getSystemDirectoryInfo() {
        $dirinfo = $this->CI->session->userdata(APP_NAME . "." . self::SYSDIR);
        return json_decode($dirinfo);
    }
    
    public function setSystemDirectoryInfo($sysdirinfo) {
        $this->CI->session->set_userdata(APP_NAME . "." . self::SYSDIR);
    }
    
    public function isReady() {
        return $this->Ready;
    }
    
    public function getSystemDirectory() {
        $dirinfo = $this->getSystemDirectoryInfo();
        if(!empty($dirinfo)):
            if(!empty($dirinfo->id)):
                $sysdir = $this->Service->files->get($dirinfo->id);
            endif;
        else:
            // there was a problem - re-make the system directory
            $params = array(
                'q'=>"mimeType = 'application/vnd.google-apps.folder' and title = '" . self::SYSDIR . "'",
                'maxResults'=>1
            );
            $gquery = $this->Service->files->listFiles($params);
            $sysdir = $gquery->getItems();
            // sysdir not found
            if(empty($sysdir)):
                // create system directory
                $sysdir = $this->newDirectory(self::SYSDIR, null, self::PERMISSION_PUBLIC);
                $this->setSystemDirectoryInfo($sysdir);
            else:
                $sysdir = $sysdir[0];
            endif;
        endif;
        // return the system directory
        return $sysdir;
    }
    
    public function getFileUrl(\Google_DriveFile $file, $parentId) {
        return "https://googledrive.com/host/{$parentId}/" . $file->title;
    }
    
    public function uploadFile($path, $title, $parentId=null, $allow=self::PERMISSION_PRIVATE) {
        /** @TODO Build in re-try parameters **/
        $newFile = new Google_DriveFile();
        if ($parentId != null) {
           $parent = new Google_ParentReference();
           $parent->setId($parentId);
           $newFile->setParents(array($parent));
        }
        $newFile->setTitle($title);
        $newFile->setDescription(APP_NAME . " file uploaded " . gmdate("jS F, Y H:i A") . " GMT");
        $newFile->setMimeType(mime_content_type($path));
        
        $permission = $this->getFilePermissions($allow);
        $remoteNewFile = $this->Service->files->insert($newFile, array(
            'data'=>file_get_contents($path),
            'mimeType'=>  mime_content_type($path)
        ));
        $fileId = $remoteNewFile->getId();
        if(!empty($fileId)):
            $this->Service->permissions->insert($fileId, $permission);
            return $remoteNewFile;
        endif;
    }
    
    public function copyFile($originFileId, $copyTitle, $parentId=null, $allow=self::PERMISSION_PRIVATE) {
        $copiedFile = new Google_DriveFile();
        $copiedFile->setTitle($copyTitle);
        try {
            // Set the parent folder.
             if ($parentId != null) {
                $parent = new Google_ParentReference();
                $parent->setId($parentId);
                $copiedFile->setParents(array($parent));
             }
            $newFile = $this->Service->files->copy($originFileId, $copiedFile);
            $permission = $this->getFilePermissions($allow);
            $this->Service->permissions->insert($newFile->getId(), $permission);
            
            return $newFile;
          
        } catch (Exception $e) {
          print "An error occurred: " . $e->getMessage();
        }
        return NULL;
    }
    
    public function newFile($title, $description, $mimeType, $filename, $parentId=null, $allow=self::PERMISSION_PRIVATE) {
        if(!$this->isReady()):
            throw new Exception("Google client is not initialized");
        endif;
        
        $file = new Google_DriveFile();
        $file->setTitle($title);
        $file->setDescription($description);
        $file->setMimeType($mimeType);
        
        // Set the parent folder.
         if ($parentId != null) {
           $parent = new Google_ParentReference();
           $parent->setId($parentId);
           $file->setParents(array($parent));
         }

         try {
           $data = file_get_contents($filename);

           $createdFile = $this->Service->files->insert($file, array(
             'data' => $data,
             'mimeType' => $mimeType,
           ));

            $permission = new Google_Permission();
            switch($allow):
                case self::PERMISSION_PRIVATE:
                    $permission->setValue('me');
                    $permission->setType('default');
                    $permission->setRole('owner');
                    break;

                default:
                    $permission->setValue('');
                    $permission->setType('anyone');
                    $permission->setRole('reader');
                    break;
            endswitch;

            $this->Service->permissions->insert($createdFile->getId(), $permission);
           
           // Uncomment the following line to print the File ID
           // print 'File ID: %s' % $createdFile->getId();

           return $createdFile;
           
         } catch (Exception $e) {
            throw new Exception("An error occurred: " . $e->getMessage());
         }
    }
    
    public function newDirectory($folderName, $parentId=null, $allow=self::PERMISSION_PRIVATE) {
        $file = new Google_DriveFile();
        $file->setTitle($folderName);
        $file->setMimeType('application/vnd.google-apps.folder');
        
        if(!$this->isReady()):
            throw new Exception("Google client is not initialized");
        endif;
        
        // Set the parent folder.
         if ($parentId != null) {
            $parent = new Google_ParentReference();
            $parent->setId($parentId);
            $file->setParents(array($parent));
         }
        
        $createdFile = $this->Service->files->insert($file, array(
            'mimeType'=>'application/vnd.google-apps.folder'
        ));
        
        $permission = new Google_Permission();
        switch($allow):
            case self::PERMISSION_PRIVATE:
                $permission->setValue('me');
                $permission->setType('default');
                $permission->setRole('owner');
                break;
            
            default:
                $permission->setValue('');
                $permission->setType('anyone');
                $permission->setRole('reader');
                break;
        endswitch;
        
        $this->Service->permissions->insert($createdFile->getId(), $permission);
        
        return $createdFile;
    }
    
    public function getFiles($pageToken=null, $filters=null) {
        try {
            if(!$this->isReady()):
                throw new Exception("Google client is not initialized");
            endif;
        
            $result = array();
            $errors = array();
            
            try {
                
                if(!empty($filters)):
                    $where = "";
                    
                    foreach($filters as $i=>$filter):
                        if($i>0):
                            $where .= " and {$filter}";
                        else:    
                            $where .= $filter;
                        endif;
                    endforeach;
                    
                    $parameters = array(
                        'q'=>$where,
                        'maxResults'=>50
                    );
                else:
                    $parameters = array(
                        // 'q'=>"mimeType != 'application/vnd.google-apps.folder' and mimeType = 'image/gif' and mimeType = 'image/jpeg' and mimeType = 'image/png'",
                        'q'=>"mimeType != 'application/vnd.google-apps.folder'",
                        'maxResults'=>50
                    );
                endif;
                
                if($pageToken):
                    $parameters['pageToken'] = $pageToken;
                endif;
                
                $files = $this->Service->files->listFiles($parameters);
                $result = array_merge($result, $files->getItems());
                $pageToken = $files->getNextPageToken();
                
            } catch (Exception $ex) {
                $pageToken = NULL;
                $errors[] = $ex->getMessage();
            }
            
            /*
            do {
                try {
                    $parameters = array(
                        //'q'=>"mimeType != 'application/vnd.google-apps.folder' and mimeType = 'image/gif' and mimeType = 'image/jpeg' and mimeType = 'image/png'",
                        'q'=>"mimeType != 'application/vnd.google-apps.folder' and mimeType = 'image/png'",
                        'maxResults'=>50
                    );
                    if($pageToken):
                        $parameters['pageToken'] = $pageToken;
                    endif;
                    $files = $this->Service->files->listFiles($parameters);
                    $result = array_merge($result, $files['items']);
                    $pageToken = $files['nextPageToken'];
                } catch (Exception $ex) {
                    $pageToken = NULL;
                    $errors[] = $ex->getMessage();
                }
            } while ($pageToken);
             */
            
            // print_r($result);
            
            return array(
                'success'=>true,
                'files'=>$result,
                'nextPageToken'=>$pageToken,
                'errors'=>$errors,
                'parameters'=>$parameters
            );
            
        } catch (Exception $ex) {
            return array('success'=>false, 'message'=>$ex->getMessage());
        }
    }
    
}
