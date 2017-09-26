<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "{{%users_to_post}}".
 *
 * @property integer $post_id
 * @property integer $user_id
 *
 * @property Post $post
 * @property User $user
 */
class UsersToPost extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%users_to_post}}';
    }
}
