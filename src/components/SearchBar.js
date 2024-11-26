import React, { useState } from 'react';
import Button from './Button';

const SearchBar = ({ onSearch }) => {
  const [input, setInput] = useState('');

  const handleInput = (e) => {
    setInput(e.target.value);
  };

  const handleSearch = () => {
    onSearch(input);
  };

  return (
    <div className="search-bar">
      <input
        type="text"
        value={input}
        placeholder="请输入关键词..."
        onChange={handleInput}
      />
      <Button onClick={handleSearch}>检索</Button>
    </div>
  );
};

export default SearchBar;