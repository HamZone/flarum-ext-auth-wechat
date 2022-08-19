<?php
namespace NomisCZ\WeChatAuth\Http\Controllers;

use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Forum\Auth\Registration;

use Flarum\Http\RememberAccessToken;
use Flarum\Http\Rememberer;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;

class WXRespFactoryController extends ResponseFactory{
/**
     * @var Rememberer
     */
    protected $rememberer;

    /**
     * @param Rememberer $rememberer
     */
    public function __construct(Rememberer $rememberer)
    {
        $this->rememberer = $rememberer;
    }

    public function make(
        string $provider, 
        string $identifier, 
        callable $configureRegistration,
        bool $isMobile = false,
        string $url = ""
        ): ResponseInterface
    {
        if ($user = LoginProvider::logIn($provider, $identifier)) {
            return $this->makeLoggedInResponse($user);
        }

        $configureRegistration($registration = new Registration);

        $provided = $registration->getProvided();

        if (! empty($provided['email']) && $user = User::where(Arr::only($provided, 'email'))->first()) {
            $user->loginProviders()->create(compact('provider', 'identifier'));

            return $this->makeLoggedInResponse($user);
        }

        $token = RegistrationToken::generate($provider, $identifier, $provided, $registration->getPayload());
        $token->save();
        app('log')->debug($isMobile);
        app('log')->debug($url);
        if($isMobile){
            return $this->makeWXResponse(array_merge(
                $provided,
                $registration->getSuggested(),
                [
                    'token' => $token->token,
                    'provided' => array_keys($provided)
                ]
            ), $url);
        }
        return $this->makeResponse(array_merge(
            $provided,
            $registration->getSuggested(),
            [
                'token' => $token->token,
                'provided' => array_keys($provided)
            ]
        ));
    }

    private function makeResponse(array $payload): HtmlResponse
    {
        app('log')->debug("makeResponse");
        $content = sprintf(
            '<script>window.close(); window.opener.app.authenticationComplete(%s);</script>',
            json_encode($payload)
        );
        return new HtmlResponse($content);
    }

    private function makeWXResponse(array $payload, $url): HtmlResponse
    {
        app('log')->debug("makeWXResponse");
        // $content = sprintf(
        //     '<script>window.opener.app.authenticationComplete(%s);</script>',
        //     json_encode($payload)
        // );
        $content="";
        $content .= "<script>window.location.href ='".$url."'</script>";
        return new HtmlResponse($content);
    }

    private function makeLoggedInResponse(User $user)
    {
        $response = $this->makeResponse(['loggedIn' => true]);

        $token = RememberAccessToken::generate($user->id);

        return $this->rememberer->remember($response, $token);
    }

}