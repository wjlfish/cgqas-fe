import React from 'react';
import '../styles/Button.css';

const Button = ({ children, onClick }) => (
  <button className="button" onClick={onClick}>
    {children}
  </button>
);

export default Button;