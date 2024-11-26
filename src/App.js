import React, { useState, useEffect } from 'react';
import './styles/App.css';
import SearchBar from './components/SearchBar';
import QuestionList from './components/QuestionList';

const App = () => {
    const [questions, setQuestions] = useState([]);
  
    const fetchQuestions = async (q = '') => {
      const url = q ? `https://api.wjlo.cc/cgtk/get?q=${encodeURIComponent(q)}` : 'https://api.wjlo.cc/cgtk/get';
      const response = await fetch(url);
      const data = await response.json();
      if (data.success) {
        setQuestions(data.data);
      }
    };
  
    useEffect(() => {
      fetchQuestions();
    }, []);
  
    const handleSearch = (q) => {
      fetchQuestions(q);
    };
  
    return (
      <div className="app">
        <header className="app-header">
        
        <h1>创高题库检索助手</h1>
        <blockquote>
            开发者不提倡也不建议任何同学在考试时使用此题库！！！
        </blockquote>
        <blockquote>
            使用本助手造成的一切后果与开发者无关！！！
        </blockquote>
        </header>
        
        <SearchBar onSearch={handleSearch} />
        <QuestionList questions={questions} />
        <footer className="app-footer">
        <p>Copyright © 2024 Kevin Wang</p>
        <p>仅用于复习学习创高体育App内的学习题目</p>
        <p>本软件在 MIT 许可证下分发</p>
        <p>Repo <a href="https://github.com/wjlfish/cdqas-fe" target="_blank" rel="noopener noreferrer">wjlfish/cgqas-fe</a></p>
        <p>Developed by <a href="https://github.com/wjlfish" target="_blank" rel="noopener noreferrer">wjlfish</a></p>
      </footer>
      </div>
    );
  };

export default App;