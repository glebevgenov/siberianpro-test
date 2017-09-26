<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "project".
 *
 * @property integer $id
 * @property string $name
 * @property integer $status
 * @property integer $project_manager
 * @property integer $tech_lead
 * @property integer $process_manager
 * @property integer $tech_dir
 * @property integer $reviewer
 * @property integer $warning_problem
 * @property integer $warning_alarm
 * @property string $slack_name
 * @property string $emoji
 *
 * @property Post[] $posts
 * @property User[] $users
 */
class Project extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%project}}';
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPosts()
    {
        return $this->hasMany(Post::className(), ['project_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUsers()
    {
        return $this->hasMany(User::className(), ['id' => 'user_id'])->viaTable('project_to_user', ['project_id' => 'id']);
    }
}
