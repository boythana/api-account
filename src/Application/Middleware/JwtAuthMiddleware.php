<?php

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtAuthMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // 1. ดักแกะ Token จาก Header
        $authHeader = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $authHeader);

        if (empty($token)) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized: Token missing']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        try {
            // 2. Decode ตรวจสอบความถูกต้อง
            $secret = $_ENV['JWT_SECRET'];
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // 3. แนบข้อมูล userId และ userRole ไปกับ $request ตัวกลาง
            $request = $request->withAttribute('userId', $decoded->uid)
                               ->withAttribute('userRole', $decoded->role);

        } catch (\Exception $e) { // 😎 เติม \ ข้างหน้าแบบนี้ เพื่อป้องกัน Class not found เวลา Token หลุดครับ
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid token']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // 4. ผ่านด่านเรียบร้อย ส่ง Request ต่อไปให้ API ทำงาน
        return $handler->handle($request);
    }
}