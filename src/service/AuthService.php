<?php

namespace App\Service;

use App\Entity\Token;
use App\Entity\TokenRepo;
use App\Entity\User;
use App\Entity\UserRepo;
use App\Entity\VergetenToken;
use App\Entity\VergetenTokenRepo;
use App\Exception\NoMailException;
use App\Exception\NotAuthenticatedException;
use App\Exception\UnknownCredentialsException;
use App\Exception\UsernameExistsException;
use Doctrine\ORM\EntityManager;
use Interop\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;

class AuthService {

    /**
     * @var int
     */
    protected $maxAge = 3600 * 8;

    /**
     * @var \Interop\Container\ContainerInterface
     */
    protected $ci;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var UserRepo
     */
    protected $userRepo;

    /**
     * @var TokenRepo
     */
    protected $tokenRepo;

    /**
     * @var VergetenTokenRepo
     */
    protected $vergetenTokenRepo;

    /**
     * AuthService constructor.
     * @param \Interop\Container\ContainerInterface $ci
     */
    public function __construct(ContainerInterface $ci) {
        $this->ci        = $ci;
        $this->em        = $this->ci->get('em');
        $this->userRepo  = $this->em->getRepository('App\Entity\User');
        $this->tokenRepo = $this->em->getRepository('App\Entity\Token');
        $this->vergetenTokenRepo = $this->em->getRepository('App\Entity\VergetenToken');
    }

    /**
     * @return string
     */
    protected function createPrefix() {
        $alphabet = './' . implode("", array_merge(range("0", "9"), range ("A", "Z"), range("a", "z")));
        $salt = "";
        while (strlen($salt) < 23) $salt .= $alphabet[mt_rand(0, strlen($alphabet)-1)];
        return '$2a$12$' . $salt;
    }

    /**
     * @return string
     */
    protected function createToken() {
        $alphabet = implode("", array_merge(range("0", "9"), range("a", "f")));
        $token = "";
        while (strlen($token) < 40) $token .= $alphabet[mt_rand(0, strlen($alphabet)-1)];
        return $token;
    }

    /**
     * @param string $username
     * @param string $password
     * @return Token
     * @throws UnknownCredentialsException
     */
    public function login($username, $password) {
        /**
         * @var User $user
         */
        $user = $this->userRepo->findOneByUsername($username);
        if (null === $user) {
            throw new UnknownCredentialsException();
        }

        if (!password_verify($password.$user->getSalt(), $user->getPassword())) {
            throw new UnknownCredentialsException();
        }

        $token = $user->getToken();
        if (null === $token) {
            $token = new Token();
            $token->setUser($user);
            $user->setToken($token);
            $this->em->persist($token);
        }

        $uuid = Uuid::uuid4();
        $token->setToken($uuid->toString());

        /**
         * This method also flushes
         */
        $this->updateToken($token);

        return $token;
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $mail
     * @return User
     * @throws UsernameExistsException
     *
     */
    public function create($username, $password, $mail = null) {
        $user = $this->userRepo->findOneByUsername($username);
        if (null !== $user) {
            throw new UsernameExistsException();
        }

        $user = new User();
        $user->setUsername($username);
        $this->setPassword($user, $password);
        $user->setMail($mail);

        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    /**
     * @param string $username
     * @param string $password
     * @return User
     * @throws UnknownCredentialsException
     *
     */
    public function update($username, $password, $mail)
    {
        /**
         * @var User $user ;
         */
        $user = $this->userRepo->findOneByUsername($username);
        if (null === $user) {
            throw new UnknownCredentialsException();
        }

        if (null !== $password) {
            $this->setPassword($user, $password);
        }

        $user->setMail($mail);

        $this->em->flush();
        return $user;
    }

    /**
     * @param User $user
     * @param string $password
     * @return User
     */
    protected function setPassword(User $user, $password) {
        $salt = $this->createPrefix();
        $user->setSalt($salt);

        $user->setPassword($this->getHash($password, $salt));

        return $user;
    }

    /**
     * @param string $password
     * @param string $salt
     * @return bool|string
     */
    protected function getHash($password, $salt) {
        $options = array('cost' => 11);
        return password_hash($password.$salt, PASSWORD_BCRYPT, $options);
    }

    /**
     * @param Token $token
     * @param bool $flush
     */
    public function updateToken(Token $token, $flush=true) {
        $now = new \DateTime();
        $token->setLastAction($now);
        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * @param $string
     * @return null|Token
     */
    public function getToken($string) {
        /**
         * @var Token $token
         */
        $token = $this->tokenRepo->findOneByToken($string);
        if (null !== $token) {
            $timestamp = $token->getLastAction()->getTimestamp();
            $now = time();
            if ($now - $this->maxAge > $timestamp) {
                $this->deleteToken($token);
                $token = null;
            }
        }
        return $token;
    }

    /**
     * @param $tokenString
     */
    public function requireAuthentication($tokenString) {
        $token = $this->getToken($tokenString);
        if (null === $token) {
            throw new NotAuthenticatedException();
        }
    }

    /**
     * @param Token $token
     * @return null
     */
    public function deleteToken(Token $token) {
        $user = $token->getUser();
        $user->setToken(null);
        $token->setUser(null);
        $this->em->remove($token);
        $this->em->flush();
    }

    /**
     * @param Token $token
     * @return int
     */
    public function getExpires(Token $token) {
        return $token->getLastAction()->getTimestamp() + $this->maxAge;
    }

    public function getAllUsers() {
        $users = $this->userRepo->findAll();
        return $users;
    }

    /**
     * @param $username
     * @return bool
     * @throws UnknownCredentialsException
     */
    public function delete($username) {
        /**
         * @var User $user
         */
        $user = $this->userRepo->findOneByUsername($username);
        if (null === $user) {
            throw new UnknownCredentialsException();
        }

        $token = $user->getToken();
        if (null !== $token) {
            $user->setToken(null);
            $token->setUser(null);
            $this->em->flush();
            $this->em->remove($token);
        }

        $this->em->remove($user);

        $this->em->flush();
        return true;
    }

    /**
     * @param string $username
     * @return null|User
     */
    public function getByUsername($username) {
        return $this->userRepo->findOneByUsername($username);
    }

    /**
     * @param $username
     * @throws UnknownCredentialsException
     */
    public function sendForgotLink($username) {
        /**
         * @var User $user
         */
        $user = $this->userRepo->findOneByUsername($username);
        if (null === $user) {
            throw new UnknownCredentialsException();
        }

        if (null === $user->getMail()) {
            throw new NoMailException();
        }

        $vergetenToken = $user->getVergetenToken();
        if (null === $vergetenToken) {
            $vergetenToken = new VergetenToken();
            $vergetenToken->setUser($user);
            $user->setVergetenToken($vergetenToken);
        }

        $uuid = Uuid::uuid4();
        $vergetenToken->setToken($uuid->toString());

        $now = new \DateTime();
        $vergetenToken->setCreated($now);

        $this->em->flush();

        $transport = \Swift_MailTransport::newInstance();
        $mailer = \Swift_Mailer::newInstance($transport);

        // Create a message
        $message = \Swift_Message::newInstance('Wachtwoord vergeten')
            ->setFrom(['noreply@tourbuzz.nl' => 'Tourbuzz wachtwoord vergeten'])
            ->setTo([$user->getMail() => $user->getUsername()])
            ->setBody('Hierbij de code: ' . $vergetenToken->getToken())
        ;

        $result = $mailer->send($message);
    }
}