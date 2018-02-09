<?php

namespace Loco\Utils\Swizzle;

use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\Guzzle\Deserializer as DefaultDeserializer;
use GuzzleHttp\Command\Guzzle\Parameter;
use GuzzleHttp\Command\Guzzle\SchemaValidator;
use GuzzleHttp\Command\Result;
use GuzzleHttp\Command\ResultInterface;
use Loco\Utils\Swizzle\Exception\ValidationException;
use Loco\Utils\Swizzle\Result\ClassResultInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Handler used to create response models based on an HTTP response and
 * a service description.
 *
 * Response location visitors are registered with this Handler to handle
 * locations (e.g., 'xml', 'json', 'header'). All of the locations of a response
 * model that will be visited first have their ``before`` method triggered.
 * After the before method is called on every visitor that will be walked, each
 * visitor is triggered using the ``visit()`` method. After all of the visitors
 * are visited, the ``after()`` method is called on each visitor. This is the
 * place in which you should handle things like additionalProperties with
 * custom locations (i.e., this is how it is handled in the JSON visitor).
 */
class Deserializer extends DefaultDeserializer
{
    /**
     * @var CommandInterface
     */
    protected $command;

    /**
     * Deserialize the response into the specified result representation
     *
     * @param ResponseInterface $response
     * @param RequestInterface|null $request
     * @param CommandInterface $command
     *
     * @return Result|ResultInterface|ResponseInterface
     * @throws \Loco\Utils\Swizzle\Exception\ValidationException
     */
    public function __invoke(ResponseInterface $response, RequestInterface $request, CommandInterface $command)
    {
        $this->command = $command;
        return parent::__invoke($response, $request, $command);
    }

    /**
     * Handles visit() and after() methods of the Response locations
     *
     * @param Parameter $model
     * @param ResponseInterface $response
     *
     * @return Result|ResultInterface|ResponseInterface|ClassResultInterface
     *
     * @throws \InvalidArgumentException
     * @throws \Loco\Utils\Swizzle\Exception\ValidationException
     */
    protected function visit(Parameter $model, ResponseInterface $response)
    {
        $errorMessage = null;

        if ($model->getType() === 'class') {
            if (isset($model->toArray()['class'])) {
                $class = $model->toArray()['class'];
                if (is_subclass_of($class, ClassResultInterface::class)) {
                    $result = $class::fromResponse($response);
                } else {
                    throw new \InvalidArgumentException(
                        'Result class should implement '.ClassResultInterface::class
                        ." Unable to deserialize response into {$class}"
                    );
                }
            } else {
                throw new \InvalidArgumentException(
                    "Model type is \"class\", but \"class\" parameter isn't defined for model {$model->getName()}"
                );
            }
        } elseif ($model->getType() === 'response') {
            return $response;
        } else {
            $result = parent::visit($model, $response);
            if (isset($model->toArray()['class'])) {
                $class = $model->toArray()['class'];
                if (is_subclass_of($class, ResultInterface::class)) {
                    $result = new $class($result->toArray());
                } else {
                    throw new \InvalidArgumentException(
                        'Result class should implement '.ResultInterface::class
                        ." Unable to deserialize response into {$class}"
                    );
                }
            }
        }

        if ($result instanceof ResultInterface) {
            $validator = new SchemaValidator();
            // @TODO: Remove this note and pass $result once PR #158 in guzzle-services is accepted
            // At the moment only arrays are correctly validated, but not ToArrayInterface objects.
            // @see https://github.com/guzzle/guzzle-services/pull/158
            $res = $result->toArray();
            if ($validator->validate($model, $res) === false) {
                throw new ValidationException(
                    'Response failed model validation: ' . implode("\n", $validator->getErrors()),
                    $this->command
                );
            }
        }
        return $result;
    }

}
