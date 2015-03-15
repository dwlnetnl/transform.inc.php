<?php

function transform() {
	$clbTransformerArray = func_get_args();
	$opqOldErrorHandler = set_error_handler('_transform_error_handler');
	$mixArgumentArray = null;
	
	foreach ($clbTransformerArray as $clbTransformer) {
		if (_transform_tuple_isValid($mixArgumentArray)) {
			$mixArgumentArray = _transform_tuple_forceUnwrap($mixArgumentArray);
		} else if ($mixArgumentArray !== null) {
			$mixArgumentArray = array($mixArgumentArray);
		} else {
			$mixArgumentArray = array();
		}
		
		$mixArgumentArray = call_user_func_array($clbTransformer, $mixArgumentArray);
	}
	
	set_error_handler($opqOldErrorHandler);
	return _transform_tuple_unwrap($mixArgumentArray);
}

function transform_debug() {
	$mixReturnValue = call_user_func_array('transform', array_map(function($clbTransformer) {
		if ($clbTransformer instanceof _transform_InputTransformer) {
			return $clbTransformer;
		}
	
		if ($clbTransformer instanceof _transform_DebugTransformer) {
			$clbTransformer = $clbTransformer->getCallable();
		}
	
		return function() use ($clbTransformer) {
			$mixArgumentArray = func_get_args();
			$strFunctionName = _transform_callable_getFunctionInfo($clbTransformer);
		
			echo "transform($strFunctionName): input begin" . PHP_EOL;
			var_dump($mixArgumentArray);
			echo "transform($strFunctionName): input end" . PHP_EOL;
		
			return call_user_func_array($clbTransformer, $mixArgumentArray);
		};
	}, func_get_args()));

	echo "transform(): output begin" . PHP_EOL;
	var_dump($mixReturnValue);
	echo "transform(): output end" . PHP_EOL;

	return $mixReturnValue;
}

function transform_debugStep(callable $clbTransformer) {
	return new _transform_DebugTransformer($clbTransformer);
}

class _transform_DebugTransformer extends _transform_Transformer {
	protected function transform(callable $clbTransformer, array $mixArgumentArray) {
		$strFunctionName = _transform_callable_getFunctionInfo($clbTransformer);
		
		echo "transform($strFunctionName): input begin" . PHP_EOL;
		var_dump($mixArgumentArray);
		echo "transform($strFunctionName): input end" . PHP_EOL;
		
		$mixReturnValue = call_user_func_array($clbTransformer, $mixArgumentArray);
		
		echo "transform($strFunctionName): output begin" . PHP_EOL;
		var_dump(_transform_tuple_unwrap($mixReturnValue));
		echo "transform($strFunctionName): output end" . PHP_EOL;
		
		return $mixReturnValue;
	}
}

function transform_input() {
	return new _transform_InputTransformer(func_get_args());
}

class _transform_InputTransformer extends _transform_Transformer {
	private $mixArgumentArray;
	
	public function __construct(array $mixArgumentArray) {
		$this->mixArgumentArray = $mixArgumentArray;
	}
	
	protected function transform(callable $clbTransformer = null, array $mixArgumentArray) {
		return _transform_tuple_wrap($this->mixArgumentArray);
	}
}

function transform_returnsTuple(callable $clbTransformer) {
	return new _transform_TupleTransformer($clbTransformer);
}

class _transform_TupleTransformer extends _transform_Transformer {
	protected function transform(callable $clbTransformer, array $mixArgumentArray) {
		$tupReturnValues = call_user_func_array($this->clbTransformer, $mixArgumentArray);
		
		if (!is_array($tupReturnValues)) {
			_transform_error_exitWithMessageCallable(function($mapTransformStackFrame) use ($clbTransformer) {
				$strFileName = null;
				$intFileLine = null;
				
				switch (_transform_callable_getType($clbTransformer)) {
					case 'closure':
						$objReflector = _transform_callable_getReflector($clbTransformer);
						
						$strCallable = 'Closure';
						$strFileName = $objReflector->getFileName();
						$intFileLine = $objReflector->getStartLine();
						break;
					case 'method':
						$strCallable = 'Method';
						break;
					case 'function':
						$strCallable = 'Function';
						break;
				}
				
				return array(
					"$strCallable is expected to return array with multiple (return) values",
					$strFileName,
					$intFileLine,
				);
			});
		}
		
		return _transform_tuple_wrap($tupReturnValues);
	}
}

function transform_returnsTuple_debugStep(callable $clbTransformer) {
	return transform_returnsTuple(transform_debugStep($clbTransformer));
}

function transform_returnsTuple_debug(callable $clbTransformer) {
	return transform_returnsTuple_debugStep($clbTransformer);
}

function _transform_callable_getFunctionInfo(callable $clbSubject) {
	$strBuffer = _transform_callable_getFunctionName($clbSubject);
	$objReflector = _transform_callable_getReflector($clbSubject);
	
	if ($objReflector->isClosure() && $objReflector->isUserDefined()) {
		$strBuffer .= ' at ';
		$strBuffer .= basename($objReflector->getFileName());
		$strBuffer .= ':';
		$strBuffer .= $objReflector->getStartLine();
		$strBuffer .= '-';
		$strBuffer .= $objReflector->getEndLine();
	}
	
	return $strBuffer;
}

function _transform_callable_getFunctionName(callable $clbSubject) {
	switch (_transform_callable_getType($clbSubject)) {
		case 'closure':
			return '{closure}';
		case 'method':
			if (is_string($clbSubject[0])) {
				$strClassName = $clbSubject[0];
			} else {
				$strClassName = get_class($clbSubject[0]);
			}
		
			return "$strClassName::{$clbSubject[1]}";
		case 'function':
			return $clbSubject;
		default:
			return false;
	}
}

function _transform_callable_getReflector(callable $clbSubject) {
	if ($clbSubject instanceof _transform_Transformer) {
		$clbSubject = $clbSubject->getCallable();
	}
	
	if ($clbSubject instanceof Closure || (is_string($clbSubject) && strpos($clbSubject, '::') === false)) {
		$objReflector = new ReflectionFunction($clbSubject);
	} else if (is_array($clbSubject)) {
		$objReflector = new ReflectionMethod($clbSubject[0], $clbSubject[1]);
	} else {
		$objReflector = new ReflectionMethod($clbSubject);
	}

	return $objReflector;
}

function _transform_callable_getType(callable $clbSubject, $blnPreprocessTransformer = true) {
	if ($clbSubject instanceof _transform_Transformer) {
		if ($blnPreprocessTransformer) {
			$clbSubject = $clbSubject->getCallable();
		} else {
			return 'transformer';
		}
	}
	
	if ($clbSubject instanceof Closure) {
		return 'closure';
	} else if (is_array($clbSubject) || (is_string($clbSubject) && strpos($clbSubject, '::') !== false)) {
		return 'method';
	} else {
		return 'function';
	}
}

function _transform_error_exitWithMessageCallable(callable $clbGetErrorMessage) {
	$mapTransformStackFrame = _transform_error_getTransformStackFrame();
	$tupErrorInfo = call_user_func($clbGetErrorMessage, $mapTransformStackFrame);
	
	if (!is_array($tupErrorInfo)) {
		throw new UnexpectedValueException('Expecting return value array(0 => string, 1 => string, 2 => numeric) got ' . gettype($tupErrorInfo));
	}
	
	if (!isset($tupErrorInfo[1])) {
		$tupErrorInfo[1] = $mapTransformStackFrame['file'];
	}
	
	if (!isset($tupErrorInfo[2])) {
		$tupErrorInfo[2] = $mapTransformStackFrame['line'];
	}
	
	if ((isset($tupErrorInfo[1]) && !is_string($tupErrorInfo[0]))
		|| (isset($tupErrorInfo[2]) && !is_string($tupErrorInfo[1]))
		|| (isset($tupErrorInfo[3]) && !is_numeric($tupErrorInfo[2])))
	{
		$strValueArray = array();
		
		foreach ($tupErrorInfo as $mixKey => $mixValue) {
			$strValueArray[] = "$mixKey => " . gettype($mixValue);
		}
		
		$strValues = implode(', ', $strValueArray);
		throw new UnexpectedValueException("Expecting return value array(0 => string, 1 => string, 2 => numeric) got array($strValues)");
	} else {
		echo PHP_EOL;
		echo "Fatal error: {$tupErrorInfo[0]} in {$tupErrorInfo[1]} on {$tupErrorInfo[2]}";
		echo PHP_EOL;
	}
	
	exit;
}

function _transform_error_getTransformStackFrame(array $mapFrameArray = null) {
	if ($mapFrameArray === null) {
		$mapFrameArray = debug_backtrace();
	}
	
	for ($i = 0, $n = sizeof($mapFrameArray); $i < $n; $i++) {
		$mapFrame = $mapFrameArray[$i];
		
		if (($mapFrame['function'] == 'transform' || $mapFrame['function'] == 'transform_debug') && isset($mapFrame['file'])) {
			$mapTransformFrame = $mapFrameArray[$i];
			break;
		}
	}
	
	assert(in_array($mapFrame['args'][0], $mapTransformFrame['args']));
	return $mapTransformFrame;
}

function _transform_error_handler($intCode, $strMessage, $strFileName, $intFileLine, array $mapContext) {
	$mapStackFrameArray = debug_backtrace();
	
	if (($mapCallStackFrame = $mapStackFrameArray[1]) && isset($mapCallStackFrame['file']) && isset($mapCallStackFrame['function'])) {
		if ($mapCallStackFrame['file'] === __FILE__ && $mapCallStackFrame['function'] === 'call_user_func_array') {
			if (error_reporting() & E_ERROR || error_reporting() & E_USER_ERROR) {
				_transform_error_exitWithMessageCallable(function($mapTransformStackFrame) use ($strMessage, $mapCallStackFrame) {
					// Handle invalid callable errors.
					if (strpos($strMessage, 'parameter 1') !== false) {
						$strMessage = str_replace('call_user_func_array', $mapTransformStackFrame['function'], $strMessage);
						$strMessage = str_replace('parameter 1 to be ', '', $strMessage);
						
						return array($strMessage);
					}
					
					// Handle invalid argument errors.
					if (strpos($strMessage, 'parameter 2') !== false) {
						$clbTransformer = $mapCallStackFrame['args'][0];
						
						$strTransformerName = _transform_callable_getFunctionName($clbTransformer);
						$strMessage = "{$mapTransformStackFrame['function']}() expects input for $strTransformerName()";
						
						// Change file and line location for closures.
						if (is_object($clbTransformer)) {
							$objReflector = _transform_callable_getReflector($clbTransformer);
							return array($strMessage, $objReflector->getFileName(), $objReflector->getStartLine());
						}
						
						return array($strMessage);
					}
					
					assert(0);
				});
			}
		}
	}
	
	return false;
}

function _transform_tuple_forceUnwrap($opqValue) {
	return $opqValue['__^^TUPLE_DATA_ARRAY__'];
}

function _transform_tuple_isValid($opqValue) {
	return is_array($opqValue) && isset($opqValue['__^^RETURNS_TUPLE__']);
}

function _transform_tuple_unwrap($opqValue) {
	if (_transform_tuple_isValid($opqValue)) {
		return _transform_tuple_forceUnwrap($opqValue);
	}
	
	return $opqValue;
}

function _transform_tuple_wrap(array $tupValues) {
	return array('__^^RETURNS_TUPLE__' => true, '__^^TUPLE_DATA_ARRAY__' => $tupValues);
}

abstract class _transform_Transformer {
	protected $clbTransformer;
	
	public function __construct($clbTransformer) {
		$this->clbTransformer = $clbTransformer;
	}
	
	public function __invoke() {
		return $this->transform($this->clbTransformer, func_get_args());
	}
	
	abstract protected function transform(callable $clbTransformer, array $mixArgumentArray);
	
	public function getCallable() {
		return $this->clbTransformer;
	}
}
