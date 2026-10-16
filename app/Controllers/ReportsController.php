<?php
namespace App\Controllers;

class ReportsController extends ApiController
{
    public function reportAction() {
        $this->requireAccess();
        return $this->json($this->reports->report($this->dispatcher->getParam('kind'), $this->request->getQuery()));
    }

    public function playerAction() {
        $this->requireAccess();
        return $this->json($this->reports->player($this->routeId('id')));
    }
}
