<?php

function transform() {
	// Set custom error handler to handle correct file and line reporting.
	$opqOldErrorHandler = set_error_handler('transform_handleCallErrors');
	
	$clbTransformerArray = func_get_args();
	$mixArgumentArray = array();
	
	for ($i = 0, $n = sizeof($clbTransformerArray); $i < $n; $i++) {
		$clbTransformer = $clbTransformerArray[$i];
		
		if (is_array($mixArgumentArray) && isset($mixArgumentArray['__^^RETURNS_TUPLE__'])) {
			$mixArgumentArray = $mixArgumentArray['__^^TUPLE_DATA_ARRAY__'];
		} else if ($mixArgumentArray !== null) {
			$mixArgumentArray = array($mixArgumentArray);
		} else {
			$mixArgumentArray = array();
		}
		
		$mixArgumentArray = call_user_func_array($clbTransformer, $mixArgumentArray);
	}
	
	// Restore error handler.
	set_error_handler($opqOldErrorHandler);
	
	// Unwrap return value if it's a tuple.
	if (is_array($mixArgumentArray) && isset($mixArgumentArray['__^^RETURNS_TUPLE__'])) {
		return $mixArgumentArray['__^^TUPLE_DATA_ARRAY__'];
	}
	
	return $mixArgumentArray;
}

function transform_handleCallErrors($intCode, $strMessage, $strFileName, $intFileLine, array $mapContext) {
	$mapStackFrameArray = debug_backtrace();
	
	if (($mapCallStackFrame = $mapStackFrameArray[1]) && isset($mapCallStackFrame['file']) && isset($mapCallStackFrame['function'])) {
		if ($mapCallStackFrame['file'] === __FILE__ && $mapCallStackFrame['function'] === 'call_user_func_array') {
			if (error_reporting() & E_ERROR || error_reporting() & E_USER_ERROR) {
				transform_exitWithError(function($mapTransformStackFrame) use ($strMessage) {
					// Handle invalid callable errors.
					if (strpos($strMessage, 'parameter 1') !== false) {
						$strMessage = str_replace('call_user_func_array', $mapTransformStackFrame['function'], $strMessage);
						$strMessage = str_replace('parameter 1 to be ', '', $strMessage);
						
						return array($strMessage);
					}
					
					// Handle invalid argument errors.
					if (strpos($strMessage, 'parameter 2') !== false) {
						$clbTransformer = $mapCallStackFrame['args'][0];
						
						$strTransformerName = transform_functionNameForCallable($clbTransformer);
						$strMessage = "{$mapTransformStackFrame['function']}() expects input for $strTransformerName()";
						
						// Change file and line location for closures.
						if (is_object($clbTransformer)) {
							$objReflector = transform_reflectorForCallable($clbTransformer);
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

function transform_exitWithError($clbGetErrorMessage) {
	$mapTransformStackFrame = transform_transformStackFrame();
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
	
	if (//sizeof($tupErrorInfo) !== 3
			/*||*/ (isset($tupErrorInfo[1]) && !is_string($tupErrorInfo[0]))
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

function transform_transformStackFrame(array $mapFrameArray = null) {
	if ($mapFrameArray === null) {
		$mapFrameArray = debug_backtrace();
	}
	
	for ($i = 0, $n = sizeof($mapFrameArray); $i < $n; $i++) {
		$mapFrame = $mapFrameArray[$i];
		
		if ($mapFrame['function'] == 'transform') {
			$mapTransformFrame = $mapFrameArray[$i];
			break;
		}
	}
	
	assert(in_array($mapFrame['args'][0], $mapTransformFrame['args']));
	return $mapTransformFrame;
}

function transform_reflectorForCallable($clbTransformer) {
	if (is_a($clbTransformer, 'Closure') || (is_string($clbTransformer) && strpos($clbTransformer, '::') === false)) {
		$objReflector = new ReflectionFunction($clbTransformer);
	} else if (is_array($clbTransformer)) {
		$objReflector = new ReflectionMethod($clbTransformer[0], $clbTransformer[1]);
	} else {
		$objReflector = new ReflectionMethod($clbTransformer);
	}

	return $objReflector;
}

function transform_functionNameForCallable($clbCallable) {
	if (is_object($clbCallable)) {
		return '{closure}';
	}
	
	if (is_array($clbCallable)) {
		if (is_string($clbCallable[0])) {
			$strClassName = $clbCallable[0];
		} else {
			$strClassName = get_class($clbCallable[0]);
		}
		
		return "$strClassName::{$clbCallable[1]}";
	}
	
	if (is_string($clbCallable)) {
		return $clbCallable;
	}
	
	return false;
}

function returnsTuple($clbFunction) {
	if (!is_callable($clbFunction, true, $strCallable)) {
		return function() use ($clbFunction) {
			transform_exitWithError(function($mapTransformStackFrame) use ($clbFunction) {
				$strVariableType = gettype($clbFunction);
				return array("'$clbFunction' of type $strVariableType is not callable");
			});
		};
	}
	
	return function() use ($clbFunction) {
		$tupReturnValues = call_user_func_array($clbFunction, func_get_args());
		
		if (!is_array($tupReturnValues)) {
			transform_exitWithError(function($mapTransformStackFrame) use ($clbFunction) {
				if (is_object($clbFunction)) {
					$objReflector = transform_reflectorForCallable($clbFunction);
					
					return array(
						'Closure is expected to return array with multiple values',
						$objReflector->getFileName(),
						$objReflector->getStartLine(),
					);
				} else if (is_array($clbSubject)) {
					return array('Method is expected to return array with multiple values');
				} else {
					return array('Function is expected to return array with multiple values');
				}
			});
		}
		
		return array('__^^RETURNS_TUPLE__' => true, '__^^TUPLE_DATA_ARRAY__' => $tupReturnValues);
	};
}
