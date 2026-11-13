<?php

namespace SchoolAid\Assistant\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;

abstract class AssistantController extends Controller
{
    use AuthorizesRequests;
}
