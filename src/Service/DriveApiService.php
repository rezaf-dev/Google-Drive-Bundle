<?php

namespace Vatri\GoogleDriveBundle\Service;

use Google_Service_Drive_DriveFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vatri\GoogleDriveBundle\DriveServiceResponse;


class DriveApiService
{

    /**
     * @var \SessionInterface
     **/
    private $session;

    /**
     * @var ParameterBagInterface
     **/
    private $parameters;

    /**
     * @var Google_Service_Drive $drive
     **/
    private $drive;

    /**
     * @var TokenStorageInterface
     */
    private $token_storage;


    public function __construct(SessionInterface $session, ParameterBagInterface $parameters, TokenStorageInterface $tokenStorage)
    {
        $this->session = $session;
        $this->parameters = $parameters;
        $this->token_storage = $tokenStorage;
    }

    /**
     *
     * https://developers.google.com/identity/protocols/OAuth2WebServer#refresh
     *
     * < They say:
     * If you use a Google API Client Library, the client object refreshes the access token as needed as long as you configure that object for offline access.
     * @param string $redirect_route_name Route to redirect to after successful login.
     *
     * @return 'ok' on valid. Current route_name on invalid.
     **/
    // public function validateAndRefreshToken($redirect_route_name){

    // 	$access_token = $this->session->get('access_token');

    // 	// 15 mins = 15x60s
    // 	if(time() - $access_token['created'] > 15 * 60){
    // 	// if(time() - $access_token['created'] >= $access_token['expires_in']){

    // 		$this->session->set('vatri_google_drive.redirect_route_on_auth', $redirect_route_name);

    // 		// $this->redirectToRoute('vatri_google_drive_auth');
    // 		return 'vatri_google_drive_auth';
    // 	}

    // 	return 'ok';
    // }

    /**
     * Generate Google_Client automatically
     *
     * @return \Google_Client
     */
    private function buildClient(): \Google_Client
    {
        $client = new \Google_Client();
        $client->setAuthConfig($this->parameters->get('vatri_google_drive.credentials_file'));
        $client->addScope(\Google_Service_Drive::DRIVE); // should have all permissions
        $client->setAccessToken($this->token_storage->getToken());

        return $client;
    }

    /**
     * Build $drive property automatically
     *
     * @return \Google_Service_Drive
     */
    private function buildDrive(): \Google_Service_Drive
    {
        $client = $this->buildClient();

        $drive = new \Google_Service_Drive($client);

        return $drive;
    }

    public function setDrive(\Google_Service_Drive $drive): self
    {
        $this->drive = $drive;

        return $this;
    }

    /**
     * Return Drive or generate if not set manualy
     *
     * @return Google_Service_Drive|\Google_Service_Drive
     */
    public function getDrive(): \Google_Service_Drive
    {

        // setDrive and getDrive is used for unit-tests...
        if ($this->drive == null) {
            return $this->buildDrive();
        }
        return $this->drive;
    }


    /**
     * @return TokenStorageInterface
     */
    public function getTokenStorage(): TokenStorageInterface
    {
        return $this->token_storage;
    }

    /**
     * Create Google Drive folder
     * @param $path For example: /path/to/folder will create 3 folders: path, to and folder.
     *
     * @param null $parentId
     * @return array|null
     */
    public function createFolder($path, $parentId = null): DriveServiceResponse
    {

        $drive = $this->buildDrive();

        $folders = explode("/", $path);

        $response = new DriveServiceResponse();

        try {

            foreach ($folders as $folder_name) {

                $fileParams = [
                    'name' => $folder_name,
                    'mimeType' => 'application/vnd.google-apps.folder'
                ];
                if ($parentId != null) {
                    $fileParams['parents'] = [$parentId];
                }

                $fileMetadata = new \Google_Service_Drive_DriveFile($fileParams);

                $res = $drive->files->create($fileMetadata, [
                    'fields' => 'id',
                    'supportsAllDrives' => true
                ]);

                $response->setResourceId($res->id);

            }
        } catch (\Exception $e) {
            $response->setError($e->getMessage());
        }

        return $response;
    }


    /**
     * @param string $folderId
     * @param bool $inTrash
     * @return bool
     */
    public function folderExists(?string $folderId, bool $inTrash = true): bool
    {
        $drive = $this->buildDrive();

        $res = null;
        try {
            $res = $drive->files->get($folderId, [
                'fields' => 'id,trashed',
                'supportsAllDrives' => true
            ]);

            if ($res->trashed) {
                return false;
            }
        } catch (\Exception $e) {
            //todo: log message
//			 echo $e->getMessage();
            return false;
        }

        return isset($res->id);

    }

    /**
     * Remove file - completely (from Trash as well).
     *
     * @param string $fileId ID of Drive file
     * @return bool
     **/
    public function deleteFile(string $fileId): bool
    {

        $drive = $this->buildDrive();

        try {
            $res = $drive->files->delete($fileId, ['supportsAllDrives' => true]);
        } catch (\Exception $e) {
            return false;
        }

        return true;

    }

    /**
     * @param string $fileId
     * @param string $newName
     */
    public function renameResource(string $fileId, string $newName)
    {
        $drive = $this->getDrive();

        $fileParams = [
            'name' => $newName,
        ];

        $fileMetadata = new \Google_Service_Drive_DriveFile($fileParams);

        try{

            $drive->files->update($fileId, $fileMetadata, ['supportsAllDrives' => true]);
            return true;

        } catch (\Exception $e){
            return false;
        }
    }

    /**
     * @param string|null $parentId
     * @param bool|null $includeTrashed
     * @param bool|null $onlyStarred
     *
     * @return \Google_Service_Drive_FileList|null
     */
    public function listFiles(?string $parentId = '', ?bool $includeTrashed = true, ?bool $onlyStarred = false, string $orderBy = 'folder,name', array $extra = []): ?\Google_Service_Drive_FileList
    {
        $filters = []; // List of queries for $q variable

        if($includeTrashed == false) {
            $filters[] = " trashed = false ";
        }
        if ($parentId != '') {
            $filters[] = " parents in '$parentId' ";
        }
        if ($onlyStarred == true) {
            $filters[] = " starred = true ";
        }
        $q = implode(" and ", $filters);

        $res = $this->getDrive()->files->listFiles(array_merge([
            'q' => $q,
            'fields' => "files/*",
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
            'orderBy' => $orderBy,
        ], $extra));

        return $res;
    }

    /**
     * Copy existing file to same or another folder.
     *
     * @param string $fileId
     * @param string|null $parentId Where to move copied file.
     */
    public function copyFile(string $fileId, ?string $parentId = null): DriveServiceResponse
    {
//        $drive = $this->generateDrive();
        $drive = $this->getDrive();

        $response = new DriveServiceResponse();

        $driveFile = new \Google_Service_Drive_DriveFile();
        if ($parentId != null) {
            $driveFile->setParents([$parentId]);
        }

        try {
            $res = $drive->files->copy($fileId, $driveFile, ['supportsAllDrives' => true]);
            $response->setResourceId($res->getId());
        } catch (\Exception $e) {
            $response->setError($e->getMessage());
        }

        return $response;
    }

    /**
     * @param string $name Name to search for
     * @param string $parentId
     */
    public function find(string $name, string $parentId = ''): ?\Google_Service_Drive_FileList
    {
        $q = "name = '$name'";
        if ($parentId != '') {
            $q .= " and parents in '$parentId' ";
        }
        $res = $this->getDrive()->files->listFiles([
            'q' => $q,
            'supportsAllDrives' => true
        ]);

        return $res;
    }

    /**
     * Find a file by ID
     * 
     * @param string $id Id to search for
     */
    public function findById(string $fileId)
    {
        $response = $this->getDrive()->files->get($fileId, [
            'fields' => '*',
            'supportsAllDrives' => true
        ]);

        return $response;
    }

    /**
     * Download a file
     * 
     * @param string $fileId File to download Id
     * @param string $filepath The path where te file will be store
     * 
     * Warning : the path start inside the public directory (need to create manually the 'downloads/' folder)
     * 
     * @return string $filepath
     */
    public function downloadFile(string $fileId, string $filepath = 'downloads/')
    {
        $drive = $this->getDrive();

        $driveFile = $this->findById($fileId);
        $filename = $driveFile->getName();

        // Check for extension
        $filename = (isset(pathinfo($filename)['extension'])) ? $filename : $filename . '.' . $driveFile->getFileExtension();

        // Differents exports links already set up for Google documents
        if ($driveFile->getExportLinks() === null) {
            $response = $drive->files->get($driveFile->getId(), array(
                'alt' => 'media'));
        } else {
            // Drive uses special MIME Types, we need to convert them
            $fileInfos = $this->getGoogleDocsFileInfos($driveFile->getMimeType());

            $response = $drive->files->export($driveFile->getId(), $fileInfos['mimeType'], array(
                'alt' => 'media'));

            $filename = $driveFile->getName() . $fileInfos['extension'];
        }

        $filepath .= $filename;

        $fileContent = $response->getBody()->getContents();
        
        file_put_contents($filepath, $fileContent);

        return $filepath;
    }

    /**
     * Convert Google Document MIME Type
     * All the Google Documents MIME Type on : https://developers.google.com/drive/api/v2/ref-export-formats
     */
    public function getGoogleDocsFileInfos(string $mimeType)
    {
        $fileInfos = [];

        switch($mimeType) {
            case 'application/vnd.google-apps.document':
                // MS Word document
                $fileInfos['mimeType'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                $fileInfos['extension'] = '.docx';
                break;
            case 'application/vnd.google-apps.spreadsheet':
                // MS Excel
                $fileInfos['mimeType'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                $fileInfos['extension'] = '.xlsx';
                break;
            case 'application/vnd.google-apps.presentation':
                // MS PowerPoint
                $fileInfos['mimeType'] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                $fileInfos['extension'] = '.pptx';
                break;
            case 'application/vnd.google-apps.drawing':
                $fileInfos['mimeType'] = 'image/png';
                $fileInfos['extension'] = '.png';
                break;
        }

        return $fileInfos;
    }

    /**
     * @return ??
     **/
    public function uploadFile(UploadedFile $file, $parentId = null): DriveServiceResponse
    {
        $drive = $this->buildDrive();

        $response = new DriveServiceResponse();

        $driveFile = new \Google_Service_Drive_DriveFile();
        $driveFile->setName($file->getClientOriginalName());
        if ($parentId) {
            $driveFile->setParents([$parentId]);
        }

        try {

            $result = $drive->files->create(
                $driveFile,
                array(
                    'data' => file_get_contents($file->getPathname()),
                    'mimeType' => $file->getClientMimeType(),
                    'uploadType' => 'multipart',
                    // 'parents'   => $parentId == null ? [] : [$parentId],
                    'supportsAllDrives' => true
                )
            );

            $response->setResourceId($result->getId());

        } catch (\Exception $e) {
            $response->setError($e->getMessage());
        }
        return $response;
    }

    /**
     * @param string $fileId
     * @param string $key
     * @param string $value
     */
    public function setStarred(string $fileId, bool $starred): DriveServiceResponse
    {
//        $fileId .= '1';
        $response = new DriveServiceResponse();

        $file = new Google_Service_Drive_DriveFile();
        $file->setStarred($starred);
        try {
            $res = $drive = $this->getDrive()->files->update($fileId, $file, ['supportsAllDrives' => true]);
            $response->setResourceId($res->getId());
        } catch (\Exception $e) {
            $response->setError($e->getMessage());
        }

        return $response;
    }

    /**
     * @return bool
     **/
    public function isTokenExpired(): bool
    {
        $access_token = $this->getTokenStorage()->getToken();

//		if( empty($access_token) || ! isset($access_token['access_token']) || ! isset($access_token['refresh_token']) ){
        if (empty($access_token) || !isset($access_token['access_token'])) {
            return true;
        }

        // Check if token will expire in 10 or less minutes:
        $expired = $this->checkExpiresIn($access_token);

        // If expired, try to refresh and check again.
        if ($expired == true) {
            // $access_token = $this->getTokenStorage()->getToken();
            // Above line won't work b/c a new cookie is visible only AFTER refreshing page
            $access_token = $this->refreshToken();

            $expired = $this->checkExpiresIn($access_token);

        }
        return $expired;
    }

    /**
     * Check existing token and it's properties and calculate if expired.
     *
     * @param array $access_token
     * @return bool
     */
    private function checkExpiresIn(array $access_token): bool
    {
        return $access_token['created'] + $access_token['expires_in'] <= time() - 10 * 60;
    }

    /**
     * Get current client, fetch new token using refresh_token, update token in a TokenStorage and return new token
     *
     * @return array
     */
    private function refreshToken(): array
    {
        $client = $this->getDrive()->getClient();
        $access_token = $client->fetchAccessTokenWithRefreshToken();

        $this->getTokenStorage()->setToken($access_token);

        return $access_token;
    }

    /**
     * @return string Symfony route name
     **/
    public function getAuthRouteName(): string
    {
        return 'vatri_google_drive_auth'; // should not be modified
    }

    /**
     * @param string $route_name
     */
    public function setRedirectPathAfterAuth(string $path): void
    {
        $this->session->set(
            $this->parameters->get('vatri_google_drive__session__key__redirect_path_after_auth'),
            $path
        );
    }
}
