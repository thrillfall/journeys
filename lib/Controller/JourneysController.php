<?php
namespace OCA\Journeys\Controller;

use OCA\Journeys\Service\TripService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class JourneysController extends Controller {
    public function __construct(
        $appName,
        IRequest $request,
        private IUserSession $userSession,
        private TripService $trips,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function journeys(): JSONResponse {
        return $this->forUser(fn(string $uid) => $this->trips->listJourneys($uid));
    }

    #[NoAdminRequired]
    public function videos(): JSONResponse {
        return $this->forUser(fn(string $uid) => $this->trips->listVideos($uid));
    }

    #[NoAdminRequired]
    public function albums(): JSONResponse {
        return $this->forUser(fn(string $uid) => ['albums' => $this->trips->manualAlbums($uid)]);
    }

    #[NoAdminRequired]
    public function rename(int $albumId, ?string $customName = null): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        try {
            $result = $this->trips->rename($user->getUID(), $albumId, $customName);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
        return $result === null
            ? new JSONResponse(['error' => 'Journey not found'], 404)
            : new JSONResponse($result);
    }

    #[NoAdminRequired]
    public function render(int $albumId, string $orientation = 'portrait'): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        if (!$this->trips->enqueueRender($user->getUID(), $albumId, $orientation)) {
            return new JSONResponse(['error' => 'Album not found or empty'], 404);
        }
        return new JSONResponse([
            'queued' => true,
            'albumId' => $albumId,
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
        ]);
    }

    private function forUser(callable $fn): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'No user'], 401);
        }
        return new JSONResponse($fn($user->getUID()));
    }
}
