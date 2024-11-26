import React from 'react';

const QuestionList = ({ questions }) => (
  <div className="question-list">
    {questions.length === 0 ? (
      <p>暂无数据</p>
    ) : (
      questions.map((q, index) => (
        <div className="question-item" key={index}>
          <h3>题目 {index + 1}</h3>
          <p><strong>问题:</strong> {q.question}</p>
          <p><strong>选项:</strong> {q.choice || '无'}</p>
          <p><strong>答案:</strong> {q.answer}</p>
        </div>
      ))
    )}
  </div>
);

export default QuestionList;