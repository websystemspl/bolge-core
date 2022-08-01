<?php
declare(strict_types = 1);

namespace Websystems\BolgeCore;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;

interface BolgeCoreInterface
{
	public function boot(Request $request);//: void;
	public function getResponse(): ?Response;
	public function setTablePrefix(string $prefix): void;
	public function getTablePrefix(): string;
	public function setDbConnectionParams(string $databaseDriver, string $databaseUser, string $databasePassword, string $databaseDbName, string $databaseHost): void;
	public function setDirPath(string $dirpath): void;
	public function getDirPath(): string;
	public function getSettings(bool $as_array = false);
	public function getRoutes(): RouteCollection;
	public function pluginActivate(): void;
}
