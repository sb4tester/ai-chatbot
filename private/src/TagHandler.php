<?php
// /home/bot.dailymu.com/private/src/TagHandler.php

class TagHandler {
    private $db;
    private $cache;

    // Pattern สำหรับตรวจจับข้อมูลต่างๆ
    private $patterns = [
        'contact' => [
            'phone' => '/(?:เบอร์|โทร)[:\s]*(\d{9,10})/i',
            'line_id' => '/(?:ไลน์|line)[:\s]*([a-z0-9_.]{4,20})/i',
            'facebook' => '/(?:เฟส|fb)[:\s]*([a-z0-9_.]{3,50})/i'
        ],
        'relationship' => [
            'status' => '/(?:โสด|มีแฟน|แต่งงาน|หย่า|เลิก)/i',
            'breakup' => '/(?:เลิก|อกหัก|นอกใจ).*?(?:เมื่อ|ช่วง)?\s*(.*?(?:วัน|เดือน|ปี|สัปดาห์))/i',
            'anniversary' => '/(?:คบ|จีบ|แต่งงาน).*?(?:มา)?\s*(.*?(?:วัน|เดือน|ปี|สัปดาห์))/i'
        ],
        'life_events' => [
            'job_change' => '/(?:เปลี่ยนงาน|ลาออก|สมัครงาน|งานใหม่)/i',
            'moving' => '/(?:ย้ายบ้าน|ย้ายที่อยู่|ย้ายที่ทำงาน)/i',
            'study' => '/(?:เรียน|สอบ|มหาลัย|โรงเรียน)/i'
        ],
        'personality' => [
            'likes' => '/(?:ชอบ|รัก|โปรด).*?([\wก-๙\s]+)/u',
            'dislikes' => '/(?:เกลียด|ไม่ชอบ).*?([\wก-๙\s]+)/u',
            'hobbies' => '/(?:งานอดิเรก|ยามว่าง).*?([\wก-๙\s]+)/u'
        ]
    ];

    public function __construct() {
        $this->db = DatabaseHandler::getInstance();
        $this->cache = new CacheHandler();
    }

    /**
     * วิเคราะห์และเก็บ tags จากบทสนทนา
     */
    public function analyzeConversation($userId, $message, $intent = null) {
        foreach ($this->patterns as $category => $patterns) {
            foreach ($patterns as $tagKey => $pattern) {
                if (preg_match($pattern, $message, $matches)) {
                    $value = isset($matches[1]) ? trim($matches[1]) : trim($matches[0]);
                    $this->addTag($userId, $tagKey, $value, [
                        'category' => $category,
                        'source' => 'conversation',
                        'context' => $message,
                        'confidence' => 0.8
                    ]);
                }
            }
        }

        // วิเคราะห์อารมณ์จากข้อความ
        $this->analyzeEmotion($userId, $message);
    }

    /**
     * เพิ่ม tag ใหม่
     */
    public function addTag($userId, $tagKey, $value, $options = []) {
        try {
            return $this->db->transaction(function($db) use ($userId, $tagKey, $value, $options) {
                // ดึง category ID
                $categoryName = $options['category'] ?? 'general';
                $categoryId = $this->getCategoryId($categoryName);

                // เช็คว่ามี tag นี้อยู่แล้วหรือไม่
                $existingTag = $db->query(
                    "SELECT * FROM user_tags WHERE user_id = ? AND tag_key = ?",
                    [$userId, $tagKey]
                )->fetch();

                if ($existingTag) {
                    // บันทึกประวัติ
                    $this->logTagHistory($userId, $existingTag['id'], $existingTag['tag_value'], $value);
                    
                    // อัพเดท tag
                    $db->query(
                        "UPDATE user_tags SET 
                         tag_value = ?,
                         confidence = ?,
                         source = ?,
                         context = ?,
                         updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?",
                        [
                            $value,
                            $options['confidence'] ?? 1.0,
                            $options['source'] ?? 'manual',
                            $options['context'] ?? null,
                            $existingTag['id']
                        ]
                    );
                } else {
                    // สร้าง tag ใหม่
                    $db->query(
                        "INSERT INTO user_tags 
                         (user_id, category_id, tag_key, tag_value, confidence, source, context)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [
                            $userId,
                            $categoryId,
                            $tagKey,
                            $value,
                            $options['confidence'] ?? 1.0,
                            $options['source'] ?? 'manual',
                            $options['context'] ?? null
                        ]
                    );
                }

                // ล้าง cache
                $this->clearUserCache($userId);

                return true;
            });
        } catch (Exception $e) {
            error_log("Error in addTag: " . $e->getMessage());
            return false;
        }
    }

    /**
     * วิเคราะห์อารมณ์จากข้อความ
     */
    private function analyzeEmotion($userId, $message) {
        $emotions = [
            'happy' => '/(?:ดีใจ|มีความสุข|สนุก|ยิ้ม|😊|😃|😄|🙂|❤️)/u',
            'sad' => '/(?:เศร้า|ร้องไห้|เสียใจ|ทุกข์|😢|😭|😔|💔)/u',
            'angry' => '/(?:โกรธ|โมโห|หงุดหงิด|😠|😡|💢)/u',
            'worried' => '/(?:กังวล|กลัว|ไม่สบายใจ|😟|😨|😰)/u',
            'love' => '/(?:รัก|หลง|คิดถึง|💕|💘|💝)/u'
        ];

        foreach ($emotions as $emotion => $pattern) {
            if (preg_match($pattern, $message)) {
                $this->addTag($userId, 'emotion', $emotion, [
                    'category' => 'personality',
                    'source' => 'analysis',
                    'context' => $message,
                    'confidence' => 0.7
                ]);
            }
        }
    }

    /**
     * ดึง tags ทั้งหมดของ user
     */
    public function getUserTags($userId, $category = null) {
        $cacheKey = "user_tags_{$userId}" . ($category ? "_{$category}" : '');
        $cached = $this->cache->get($cacheKey);
        
        if ($cached) {
            return $cached;
        }

        try {
            $sql = "SELECT t.*, c.name as category_name 
                    FROM user_tags t 
                    JOIN tag_categories c ON t.category_id = c.id 
                    WHERE t.user_id = ?";
            $params = [$userId];

            if ($category) {
                $sql .= " AND c.name = ?";
                $params[] = $category;
            }

            $sql .= " ORDER BY t.created_at DESC";

            $tags = $this->db->query($sql, $params)->fetchAll();
            
            $this->cache->set($cacheKey, $tags);
            
            return $tags;
        } catch (Exception $e) {
            error_log("Error in getUserTags: " . $e->getMessage());
            return [];
        }
    }

    /**
     * บันทึกประวัติการเปลี่ยนแปลง tag
     */
    private function logTagHistory($userId, $tagId, $oldValue, $newValue) {
        try {
            $this->db->query(
                "INSERT INTO tag_history 
                 (user_id, tag_id, old_value, new_value, changed_by)
                 VALUES (?, ?, ?, ?, ?)",
                [
                    $userId,
                    $tagId,
                    $oldValue,
                    $newValue,
                    'system'
                ]
            );
        } catch (Exception $e) {
            error_log("Error in logTagHistory: " . $e->getMessage());
        }
    }

    /**
     * ดึงหรือสร้าง category ID
     */
    private function getCategoryId($categoryName) {
        $category = $this->db->query(
            "SELECT id FROM tag_categories WHERE name = ?",
            [$categoryName]
        )->fetch();

        if ($category) {
            return $category['id'];
        }

        $this->db->query(
            "INSERT INTO tag_categories (name) VALUES (?)",
            [$categoryName]
        );

        return $this->db->getConnection()->lastInsertId();
    }

    /**
     * ล้าง cache ของ user
     */
    private function clearUserCache($userId) {
        $this->cache->delete("user_tags_{$userId}");
        foreach ($this->patterns as $category => $patterns) {
            $this->cache->delete("user_tags_{$userId}_{$category}");
        }
    }

    /**
     * สร้าง user profile จาก tags
     */
    public function generateUserProfile($userId) {
        $tags = $this->getUserTags($userId);
        $profile = [];

        foreach ($tags as $tag) {
            if ($tag['confidence'] >= 0.7) {
                $category = $tag['category_name'];
                if (!isset($profile[$category])) {
                    $profile[$category] = [];
                }
                $profile[$category][$tag['tag_key']] = [
                    'value' => $tag['tag_value'],
                    'confidence' => $tag['confidence'],
                    'updated_at' => $tag['updated_at']
                ];
            }
        }

        return $profile;
    }
}