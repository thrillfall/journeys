<?php
namespace OCA\Journeys\Controller;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;

class ExampleController extends Controller {
    public function index() {
        return new TemplateResponse('journeys', 'main');
    }
}
