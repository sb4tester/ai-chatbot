<?php
// /home/bot.dailymu.com/private/src/UserHandler.php
require_once __DIR__ . '/CacheHandler.php';
require_once __DIR__ . '/DatabaseHandler.php';


class UserHandler {
    private $db;
    private $cache;

    public function __construct() {
        $this->db = DatabaseHandler::getInstance();
        $this->cache = new CacheHandler();
    }

    public function getOrCreateUser($platform, $platformId, $additionalData = []) {
    $cacheKey = "user_{$platform}_{$platformId}";
    $cachedUser = $this->cache->get($cacheKey);
    
    if ($cachedUser) {
        return $cachedUser;
    }

    try {
        return $this->db->transaction(function($db) use ($platform, $platformId, $additionalData, $cacheKey) {
            $user = $db->query(
                "SELECT * FROM users WHERE platform = ? AND platform_id = ?",
                [$platform, $platformId]
            )->fetch();

            if (!$user) {
                // สร้าง user พร้อมข้อมูลเพิ่มเติม
                $fields = ['platform', 'platform_id'];
                $values = [$platform, $platformId];
                $placeholders = ['?', '?'];

                foreach ($additionalData as $field => $value) {
                    $fields[] = $field;
                    $values[] = $value;
                    $placeholders[] = '?';
                }

                $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $db->query($sql, $values);
                
                $user = $db->query(
                    "SELECT * FROM users WHERE platform = ? AND platform_id = ?",
                    [$platform, $platformId]
                )->fetch();
            }

            $this->cache->set($cacheKey, $user);
            return $user;
        });

    } catch (Exception $e) {
        error_log("Error in getOrCreateUser: " . $e->getMessage());
        throw $e;
    }
}
/*
    public function getOrCreateUser($platform, $platformId) {
        $cacheKey = "user_{$platform}_{$platformId}";
        $cachedUser = $this->cache->get($cacheKey);
        
        if ($cachedUser) {
            return $cachedUser;
        }

        try {
            return $this->db->transaction(function($db) use ($platform, $platformId, $cacheKey) {
                // ค้นหา user ที่มีอยู่
                $user = $db->query(
                    "SELECT * FROM users WHERE platform = ? AND platform_id = ?",
                    [$platform, $platformId]
                )->fetch();

                if (!$user) {
                    // สร้าง user ใหม่
                    $db->query(
                        "INSERT INTO users (platform, platform_id) VALUES (?, ?)",
                        [$platform, $platformId]
                    );
                    
                    $user = $db->query(
                        "SELECT * FROM users WHERE platform = ? AND platform_id = ?",
                        [$platform, $platformId]
                    )->fetch();
                }

                // อัพเดท last_visit
                $db->query(
                    "UPDATE users SET last_visit = CURRENT_TIMESTAMP WHERE id = ?",
                    [$user['id']]
                );

                $this->cache->set($cacheKey, $user);
                return $user;
            });

        } catch (Exception $e) {
            error_log("Error in getOrCreateUser: " . $e->getMessage());
            throw $e;
        }
    }
    */

    public function updateUser($userId, $data) {
    try {
        error_log("Updating user {$userId} with data: " . print_r($data, true));
        // แปลงรูปแบบวันเกิดถ้ามี
        if (isset($data['birth_date'])) {
            $birthDate = $this->formatBirthDate($data['birth_date']);
            if ($birthDate) {
                $data['birth_date'] = $birthDate;
                // คำนวณราศีอัตโนมัติ
                $zodiac = $this->calculateZodiac(
                    (int)date('m', strtotime($birthDate)),
                    (int)date('d', strtotime($birthDate))
                );
                if ($zodiac) {
                    $data['zodiac'] = $zodiac;
                }
            }
        }

        $updates = [];
        $values = [];
        foreach ($data as $field => $value) {
            $updates[] = "$field = ?";
            $values[] = $value;
        }
        
        if (empty($updates)) {
            return false;
        }

        $values[] = $userId;
        // แก้ไขตรงนี้ ลบ comma ตัวสุดท้าย
        $sql = "UPDATE users SET " . implode(', ', $updates) . 
               " WHERE id = ?"; // ลบ comma หน้า WHERE

        $this->db->query($sql, $values);

        // ล้าง cache
        $user = $this->getUserById($userId);
        if ($user) {
            $cacheKey = "user_{$user['platform']}_{$user['platform_id']}";
            $this->cache->delete($cacheKey);
        }

        return true;

    } catch (Exception $e) {
        error_log("Error in updateUser: " . $e->getMessage());
        return false;
    }
}

    private function formatBirthDate($date) {
    try {
        // กรณีได้วันที่จาก Dialogflow (format: YYYY-MM-DDThh:mm:ss+07:00)
        if (strpos($date, 'T') !== false) {
            $datetime = new DateTime($date);
            return $datetime->format('Y-m-d');
        }
        
        // กรณีได้วันที่แบบ d/m/Y (เช่น 1/1/2530)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
            $day = (int)$matches[1];
            $month = (int)$matches[2];
            $year = (int)$matches[3];
            
            // แปลง พ.ศ. เป็น ค.ศ.
            if ($year > 2400) {
                $year -= 543;
            }

            // ตรวจสอบความถูกต้องของวันที่
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return null;
        
    } catch (Exception $e) {
        error_log("Error formatting date: " . $e->getMessage());
        return null;
    }
}

    /**
     * ดึงข้อมูล User Profile แบบสมบูรณ์
     */
   public function getUserProfile($platformId) {
        try {
            // แก้ไขการ query ให้ใช้ platform_id แทน id
            $sql = "SELECT 
                        u.*,
                        (SELECT 
                            JSON_OBJECTAGG(t.tag_key, t.tag_value)
                         FROM user_tags t 
                         WHERE t.user_id = u.id
                        ) as tags,
                        (SELECT 
                            COUNT(*)
                         FROM fortune_history f 
                         WHERE f.user_id = u.id
                        ) as fortune_count,
                        (SELECT 
                            created_at
                         FROM fortune_history f 
                         WHERE f.user_id = u.id 
                         ORDER BY created_at DESC 
                         LIMIT 1
                        ) as last_fortune_date
                    FROM users u
                    WHERE u.platform_id = ?";

            return $this->db->query($sql, [$platformId])->fetch();
        } catch (Exception $e) {
            error_log("Error in getUserProfile: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * ตรวจสอบและอัพเดทข้อมูลจากบทสนทนา
     */
    public function processConversation($userId, $message) {
        // ตรวจจับชื่อเล่น
        if (preg_match('/ชื่อ(?:ว่า)?[\s:]*([\wก-๙]+)/u', $message, $matches)) {
            $this->updateUser($userId, ['nickname' => $matches[1]]);
        }

        // ตรวจจับวันเกิด
        if (preg_match('/เกิดวันที่[\s:]*([\d]{1,2})[\s\/\-]([\d]{1,2})(?:[\s\/\-]([\d]{4}))?/u', $message, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = isset($matches[3]) ? $matches[3] : date('Y');
            if ($year < 2400) { // แปลง พ.ศ. เป็น ค.ศ.
                $year -= 543;
            }
            $birth_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $this->updateUser($userId, ['birth_date' => $birth_date]);
            
            // คำนวณราศี
            $zodiac = $this->calculateZodiac($month, $day);
            if ($zodiac) {
                $this->updateUser($userId, ['zodiac' => $zodiac]);
            }
        }
    }

    /**
     * คำนวณราศีจากวันเดือนเกิด
     */
    private function calculateZodiac($month, $day) {
        $zodiacDates = [
            ['name' => 'ราศีมังกร', 'start' => ['month' => 12, 'day' => 22], 'end' => ['month' => 1, 'day' => 19]],
            ['name' => 'ราศีกุมภ์', 'start' => ['month' => 1, 'day' => 20], 'end' => ['month' => 2, 'day' => 18]],
            ['name' => 'ราศีมีน', 'start' => ['month' => 2, 'day' => 19], 'end' => ['month' => 3, 'day' => 20]],
            ['name' => 'ราศีเมษ', 'start' => ['month' => 3, 'day' => 21], 'end' => ['month' => 4, 'day' => 19]],
            ['name' => 'ราศีพฤษภ', 'start' => ['month' => 4, 'day' => 20], 'end' => ['month' => 5, 'day' => 20]],
            ['name' => 'ราศีเมถุน', 'start' => ['month' => 5, 'day' => 21], 'end' => ['month' => 6, 'day' => 20]],
            ['name' => 'ราศีกรกฎ', 'start' => ['month' => 6, 'day' => 21], 'end' => ['month' => 7, 'day' => 22]],
            ['name' => 'ราศีสิงห์', 'start' => ['month' => 7, 'day' => 23], 'end' => ['month' => 8, 'day' => 22]],
            ['name' => 'ราศีกันย์', 'start' => ['month' => 8, 'day' => 23], 'end' => ['month' => 9, 'day' => 22]],
            ['name' => 'ราศีตุลย์', 'start' => ['month' => 9, 'day' => 23], 'end' => ['month' => 10, 'day' => 22]],
            ['name' => 'ราศีพิจิก', 'start' => ['month' => 10, 'day' => 23], 'end' => ['month' => 11, 'day' => 21]],
            ['name' => 'ราศีธนู', 'start' => ['month' => 11, 'day' => 22], 'end' => ['month' => 12, 'day' => 21]]
        ];

        foreach ($zodiacDates as $zodiac) {
            if ($this->isDateInRange($month, $day, $zodiac['start'], $zodiac['end'])) {
                return $zodiac['name'];
            }
        }

        return null;
    }

    /**
     * เช็คว่าวันที่อยู่ในช่วงที่กำหนดหรือไม่
     */
    private function isDateInRange($month, $day, $start, $end) {
        $date = $month * 100 + $day;
        $startDate = $start['month'] * 100 + $start['day'];
        $endDate = $end['month'] * 100 + $end['day'];

        if ($startDate > $endDate) { // กรณีข้ามปี (เช่น ราศีมังกร)
            return $date >= $startDate || $date <= $endDate;
        }

        return $date >= $startDate && $date <= $endDate;
    }

    /**
     * ดึงข้อมูล User จาก ID
     */
    public function getUserById($userId) {
        try {
            return $this->db->query(
                "SELECT * FROM users WHERE id = ?",
                [$userId]
            )->fetch();
        } catch (Exception $e) {
            error_log("Error in getUserById: " . $e->getMessage());
            return null;
        }
    }
}