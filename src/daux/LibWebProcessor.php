<?php
namespace Todaymade\Daux\Extension;

use Todaymade\Daux\Tree\Root;
use Todaymade\Daux\Tree\Builder;
use PhpParser\ParserFactory;
use Webmozart\PathUtil\Path;
use Webmozart\Glob\Glob;

class LibWebProcessor extends \Todaymade\Daux\Processor {

	/**
	 * Create the API tree
	 */
	public function manipulateTree(Root $root) {
		
		$config = $root->getConfig();
		$config = @$config["libweb"];
		if ( !$config )
			return;

		$namespace = @$config["namespace"];
		if ( !$namespace )
			return;

		// Include files
		if ( isset( $config["include"] ) ) {
			$baseDir = $this->daux->getParams()->getDocumentationDirectory();
			$path    = is_array( $config["include"] ) ? $config["include"] : array( $config["include"] );

			$files  = array();
			$ignore = array();
			foreach ( array_reverse( $path ) as $p ) {
				if ( $p[0] === '!' ) {
					$ignore[] = Path::makeAbsolute( substr( $p, 1 ), $baseDir );
					continue;
				}

				$thisFiles = Glob::glob( Path::makeAbsolute( $p, $baseDir ) );
				$thisFiles = array_filter( $thisFiles, function( $file ) use ( $ignore ) {
					foreach ( $ignore as $i ) {
						if ( Glob::match($file, $i) )
							return false;
					}
					return true;
				});
				$files += $thisFiles;
			}
			   
			foreach ( $files as $file )
				require_once $file;
		}
		
		$composer = require "vendor/autoload.php";
		$classmap = array_keys( $composer->getClassMap() );

		$classes = get_declared_classes() + $classmap;

		$processed = array();
		foreach ( $classes as $class ) {
			if ( isset( $processed[$class]) )
				continue;
			if ( substr( $class, 0, strlen($namespace) ) !== $namespace )
				continue;
			$processed[ $class ] = true;

			$relative = substr( $class, strlen( $namespace ) );
			$this->setupAPI( $root, $class, $relative );
		}
	}

	/**
	 * Setup an API object
	 */
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

	/**
	 * Create a new page from a Reflection class
	 */
	private function setupAPIFromReflection( $page, $reflectionClass ) {
		$content = array();

		$methods = $this->parseMethods( $reflectionClass );
		foreach ( $methods as $method ) {

			$description = $method->description;
			if ( !trim( str_replace( "\n", "", $description ) ) )
				$description = "&nbsp;";
			
			$desc = "### **".$method->method.'** '.$method->name."\n\n\n<div class=\"s-content__block\">\n\n\n";

			if ( $method->params ) {
				$desc .= "**Params**\n";
				foreach ( $method->params  as $name => $param ) {
					$desc .= str_repeat( "  ", $param->offset )."- *{$name}*: {$param->description}\n";
				}
			}
			$desc .= $description."\n\n\n</div>\n\n\n";

			echo $desc, "\n";
			
			$desc .= "```\n".$method->code."\n```";
			$content[] = $desc;
		}

		$style = "\n\n<style>.Columns__right--float .s-content .s-content__block { 
float: left;
clear: left;
width: 47%;
margin-left: 1.5%;
margin-right: 1.5%;
}
.s-content .s-content__block p, .s-content .s-content__block ul { 
width: auto;
}
.s-content .s-content__block p { 
margin-bottom: 0;
}
</style>\n\n";
		$page->setContent( $style.implode( "\n", $content ) );
	}

	/**
	 * Parse every method trying to find APIs
	 */
	private function parseMethods( $reflectionClass ) {
		$methods = array();
		foreach ( $reflectionClass->getMethods() as $method ) {
			$name = $method->getName();

			if ( !preg_match( '/(POST|GET)_(\w+)/', $name, $matches ) )
				continue;

			$desc = (object) array(
				"name"        => mb_strtolower( preg_replace( "/([a-z])([A-Z])/", '$1-$2', $matches[2] ) ),
				"method"      => $matches[1],
				"description" => $this->parseDocComment( $method->getDocComment() ),
				"code"        => $this->getMethodCode( $method ),
			);
			$desc->params = $this->getMethodParams( $desc );
			$methods[] = $desc;
		}
		usort( $methods, function( $a, $b ) {
			$method = strcmp( $a->method, $b->method );
			if ( $method !== 0 )
				return $method;
			return strcmp( $a->name, $b->name );
		});
		return $methods;
	}

	/**
	 * Parse the doc comment
	 */
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

	/**
	 * Get the code from the method
	 */
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
	/**
	 * Get the method parameters
	 */
	private function getMethodParams( $desc ) {
		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
		$code   = "<?php\n".$desc->code;
		$ast = $parser->parse( $code );
		$params = array();
		self::getMethodParamsFromAST( $params, $ast, explode( "\n", $code ) );
		return $params;
	}
	/**
	 *
	 */
	private static function getMethodParamsFromAST( &$params, $node, $lines ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $childNode )
				self::getMethodParamsFromAST( $params, $childNode, $lines );
			return;
		}

		if ( $node instanceof \PhpParser\Node\Expr\MethodCall ) {
			if ( $node->name == 'param' ) {
				$line = trim( $lines[$node->getLine() - 1 ] );
				$key = $node->args[0]->value->value;
				if ( count($node->args) >= 3 )
					$param = self::parseParam( $key, $node->args[2]->value, $line );
				else
					$param = self::parseParam( $key, @$node->args[1] ? $node->args[1]->value : null, $line );

				$params[ $key ] = $param;

				self::parseParamNode( $params, $param->key, $param->validator, $lines, 1 );
				return;
			} else if ( $node->name == 'params' ) {
				foreach ( $node->args[0]->value->items as $item ) {
					$line  = trim( $lines[$item->getLine() - 1 ] );
					$key   = $item->key->value;
					$param = self::parseParam( $key, $item->value, $line );
					$params[ $key ] = $param;
					
					self::parseParamNode( $params, $param->key, $param->validator, $lines, 1 );
				}
				return;
			}
		}

		if ( isset( $node->expr ) )
			self::getMethodParamsFromAST( $params, $node->expr, $lines );
		if ( isset( $node->args ) ) {
			foreach ( $node->args as $childNode )
				self::getMethodParamsFromAST( $params, $childNode->value, $lines );
		}
		
	}
	/**
	 * Faz o parse do parametro
	 */
	private static function parseParamNode( &$params, $prefix, $node, $lines, $offset = 0 ) {
		if ( !$node )
			return;
		
		if ( $node instanceof \PhpParser\Node\Expr\Array_ ) {
			foreach ( $node->items as $item ) {
				$line  = trim( $lines[$item->getLine() - 1 ] );
				$key   = ( $prefix ? $prefix."." : "").$item->key->value;
				$subparam = self::parseParam( $key, $item->value, $line, $offset );
				$params[ $key ] = $subparam;
					
				self::parseParamNode( $params, $key, $subparam->validator, $lines, $offset + 1 );
			}
		} else if ( $node instanceof \PhpParser\Node\Expr\StaticCall ) {
			if ( $node->class->parts !== array( "v" ) )
				return;
			if ( $node->name === 'arrayOf' )
				self::parseParamNode( $params, $prefix."[n]", $node->args[0]->value, $lines, $offset + 1  );
		}
	}

	/**
	 * Faz o parse do parametro
	 */
	private static function parseParam( $key, $validatorNode, $line, $offset = 0 ) {
		$param = (object) array(
			"key"         => $key,
			"validator"   => $validatorNode,
			"offset"      => $offset,
			"description" => "",
		);
		if ( preg_match( "/\/\/(.*)?$/", $line, $matches ) ) {
			$param->description = trim( $matches[1] );
		} else if ( preg_match( "/\/\*(.*?)\*\/$/", $line, $matches ) ) {
			$param->description = trim( $matches[1] );
		}
		return $param;
	}
};