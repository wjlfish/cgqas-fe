# 项目说明（供后续会话参考）

创高体育理论考试题库检索站点的源码与运维工作区。

## 边界

- 本仓库是**公开**的：不写服务器地址、账号、口令、密钥，不写防护实现细节。
- 私有信息放在 `.private/`（已 gitignore），包括：服务器连接方式、后台路径、数据库口令、云服务密钥。
- 需要用到私有信息时，读 `.private/secrets.txt`，但**不要把它复制进仓库文件或提交**。

## 目录

```
index.php about.php feedback.php   页面
api.php go.php                     接口与跳转
lib/                               配置、数据库、鉴权、限流、地域、验证码
partials/ assets/ admin/ ops/ sql/ 模板、静态资源、后台、脚本、建表
.private/                          本地私有（不入库）
```

## 常用命令

```bash
# 部署前自检：旧路径、接口动作、JS 语法
bash ops/check-urls.sh

# 静态资源按内容哈希重新生成（改了 css/js 必须跑）
php ops/build-assets.php .

# 按科目生成检索推荐词索引（题库或检索习惯变化后跑）
php ops/build-suggest.php .
```

## 约定

- 改接口路径或新增 action 后，先跑 `ops/check-urls.sh`，它会校验前端调用的每个 action 在 `api.php` 中都有实现。
- 页面与接口对外不暴露 `.php`：页面走 `/about`、`/feedback`，接口走 `/api/<action>`。
- 静态资源用内容哈希文件名，改完必须重建，否则页面仍指向旧文件。
- 题库规模、服务器信息、后台路径不对外公示。
- README 面向外部读者，只写"是什么、怎么部署"，不写内部实现与运维细节。

## 部署

连接方式与目标见 `.private/secrets.txt`。流程：

1. 本地改完先跑自检
2. 同步代码到服务器
3. 重建静态资源
4. 重载 PHP-FPM
5. 冒烟测试：首页、`/about`、`/api/subjects`、后台登录
