<?php
namespace Todaymade\Daux\Extension;

use Todaymade\Daux\Tree\Root;
use Todaymade\Daux\Tree\Builder;

class LibWebProcessor extends \Todaymade\Daux\Processor {
	
	public function manipulateTree(Root $root) {
		
		$config = $root->getConfig();
		$config = @$config["libweb"];
		if ( !$config )
			return;

		$namespace = $config["namespace"];
		if ( !$namespace )
			return;
		
		$composer = require "vendor/autoload.php";
		$classmap = $composer->getClassMap();
		foreach ( $classmap as $class => $file ) {
			if ( substr( $class, 0, strlen($namespace) ) !== $namespace )
				continue;

			$relative = substr( $class, strlen( $namespace ) );
			$this->setupAPI( $root, $class, $relative );
		}
	}
	
	private function setupAPI( $root, $class, $relative ) {
		if ( $relative === 'API' )
			return;

		$path = explode( '\\', $relative );
		$pagename = array_pop( $path );
		$pagename = substr( $pagename, 0, -3 );
		
		$page = Builder::getOrCreateDir( $root, 'API' );
		foreach ( $path as $p )
			$page = Builder::getOrCreateDir( $page, $p );

		$page = Builder::getOrCreatePage( $page, mb_strtolower( $pagename ) );

		$reflection = new \ReflectionClass( $class );
		$this->setupAPIFromReflection( $page, $reflection );
	}

	private function setupAPIFromReflection( $page, $reflectionClass ) {
		$content = array();

		$methods = $this->parseMethods( $reflectionClass );
		foreach ( $methods as $method ) {

			$description = $method->description;
			if ( !trim( str_replace( "\n", "", $description ) ) )
				$description = "&nbsp;";
			
			$desc = "### **".$method->method.'** '.$method->name."\n\n";
			$desc .= $description."\n";
			$desc .= "```\n".$method->code."\n```";
			$content[] = $desc;
		}

		$page->setContent( implode( "\n", $content ) );
	}

	private function parseMethods( $reflectionClass ) {
		$methods = array();
		foreach ( $reflectionClass->getMethods() as $method ) {
			$name = $method->getName();

			if ( !preg_match( '/(POST|GET)_(\w+)/', $name, $matches ) )
				continue;

			$methods[] = (object) array(
				"name"        => mb_strtolower( preg_replace( "/([a-z])([A-Z])/", '$1-$2', $matches[2] ) ),
				"method"      => $matches[1],
				"description" => $this->parseDocComment( $method->getDocComment() ),
				"code"        => $this->getMethodCode( $method ),
			);
		}
		usort( $methods, function( $a, $b ) {
			$method = strcmp( $a->method, $b->method );
			if ( $method !== 0 )
				return $method;
			return strcmp( $a->name, $b->name );
		});
		return $methods;
	}

	private function parseDocComment( $comment ) {
		$comment = substr( $comment, 3, -2 );

		$lines = array_map(function( $line ) {
			$line = trim( $line );
			if ( @$line[0] === '*' ) {
				if ( @$line[1] === ' ' ) 
					$line = substr( $line, 2 );
				else
					$line = substr( $line, 1 );
			}
			return $line;
		}, explode( "\n", $comment ) );
		
		return implode( "\n", $lines );
	}
	
	private function getMethodCode( $method ) {
		$lines = file( $method->getFileName() );
		$code = array_slice( $lines, $method->getStartLine(), $method->getEndLine() - $method->getStartLine() - 1 );


		$whitespaceStart = null;
		foreach ( $code as $line ) {
			if ( trim( $line ) ) {
				if ( preg_match( '/^(\s+)/', $line, $matches ) )
					$whitespaceStart = $matches[1];
				break;
			}
		}
		if ( $whitespaceStart ) {
			foreach ( $code as &$line ) {
				if ( substr( $line, 0, strlen( $whitespaceStart ) ) === $whitespaceStart )
					$line = substr( $line, strlen( $whitespaceStart ) );
				$line = str_replace( "\t", "    ", $line );
			}
			unset( $line );
		}
		
		return implode( "", $code );
	}
};