<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'block_id' => $this->block_id,
            'question_text' => $this->question_text,
            'type' => $this->type,
            'options' => $this->options,
            'difficulty_level' => $this->difficulty_level,
            'reward' => $this->reward,
            'required_to_pass_block' => $this->required_to_pass_block,
            'answer_explanation' => $this->answer_explanation,
            'position' => $this->position,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
