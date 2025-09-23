<?php

include './vendor/autoload.php';

require_once("config/connection.php");
require_once("models/player.php");
require_once("models/teamPoints.php");

$playersRepo = new Player();

$players = $playersRepo->getAllPlayers();

