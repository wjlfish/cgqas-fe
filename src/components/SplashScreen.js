import React, { useState, useEffect } from "react";
import { motion } from "framer-motion";
import Button from './Button';

const SplashScreen = ({ onClose }) => {
  const [isVisible, setIsVisible] = useState(true);

  useEffect(() => {
    const timer = setTimeout(() => {
      setIsVisible(false);
      onClose();
    }, 10000);

    return () => clearTimeout(timer);
  }, [onClose]);

  const variants = {
    visible: { opacity: 1, scale: 1 },
    hidden: { opacity: 0, scale: 0.9 },
  };

  return (
    isVisible && (
      <motion.div
        className="splash-screen-overlay"
        initial="hidden"
        animate="visible"
        exit="hidden"
        variants={variants}
        transition={{ duration: 0.5 }}
      >
        <div className="splash-screen-content">
          <h1>致爬虫开发者</h1>
          <p>本网站自建立起，就在经受各类爬虫的数据爬取。</p>
          <p>基于MIT协议，我完全可以向各位开发者公开题库内容。</p>
          <p>无端的爬取行为，无疑会极大加重服务器的运行压力。</p>
          <p>不但会使你们得不到任何益处，也不利于本网站的良性发展。</p>
          <p>页面下方已标注开源仓库，内附我的联系方式。</p>
          <p>若想获取题库数据，可根据README与我取得联系</p>
          <Button onClick={() => { setIsVisible(false); onClose(); }}>
            关闭
          </Button>
        </div>
      </motion.div>
    )
  );
};

export default SplashScreen;